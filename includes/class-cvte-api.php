<?php
/**
 * Conversa com o webservice de cálculo de frete da Total Express.
 *
 * ---------------------------------------------------------------------------
 * O QUE É SOAP, EM UMA FRASE
 * ---------------------------------------------------------------------------
 * É um formato antigo de API em que a pergunta e a resposta são documentos
 * XML com um "envelope" padronizado. Em vez de depender da extensão SOAP do
 * PHP (que pode não estar ativa na hospedagem), montamos o envelope à mão e o
 * enviamos com a função HTTP do próprio WordPress. Funciona em qualquer lugar.
 *
 * O formato segue o WSDL oficial (webservice_calculo_frete.php?wsdl):
 *   pergunta:  TipoServico, CepDestino, Peso, ValorDeclarado, TipoEntrega,
 *              Altura, Largura, Profundidade
 *   resposta:  CodigoProc (1 = sucesso), DadosFrete { Prazo, ValorServico },
 *              ErroConsultaFrete (texto do erro, quando há)
 * A autenticação é HTTP Basic (usuário e senha do webservice).
 */

if (!defined('ABSPATH')) {
    exit;
}

class CVTE_API
{
    /** Opção com o número da "geração" do cache. Mudar o número invalida tudo. */
    const OPCAO_VERSAO_CACHE = 'cvte_cache_versao';

    /**
     * Cota UM volume.
     *
     * @param bool $guardar_falhas se uma falha deve ficar guardada por 5 minutos.
     *   Falso no teste do painel (um erro ali não pode esconder o frete dos
     *   clientes) e quando o tempo da consulta foi encurtado pelo limite do
     *   pedido (o "timeout" foi nosso, não da Total Express).
     *
     * @return array {
     *   ok: bool, valor: float, prazo: int, erro: string,
     *   cache: bool (veio do cache?), xml_enviado: string, xml_recebido: string
     * }
     */
    public static function cotar(string $cep, float $peso_kg, float $valor_declarado, array $dims_cm, array $cfg, bool $guardar_falhas = true): array
    {
        $cep = preg_replace('/\D/', '', $cep);
        if (strlen($cep) !== 8) {
            return self::falha('CEP inválido: ' . $cep);
        }

        $cred = CVTE_Config::credenciais();
        if ($cred['usuario'] === '' || $cred['senha'] === '') {
            return self::falha($cred['origem'] === 'ilegivel'
                ? 'A senha salva não pôde ser lida (as chaves secretas do WordPress mudaram). Digite-a de novo em WooCommerce → Total Express.'
                : 'Credenciais não configuradas (WooCommerce → Total Express, ou CVTE_USUARIO / CVTE_SENHA no wp-config.php).');
        }

        // As medidas vão inteiras, como pede o WSDL (nonNegativeInteger).
        $dims = array_map(static fn($d) => (int) ceil((float) $d), array_values($dims_cm));

        $params = [
            'TipoServico'    => $cfg['servico'],
            'CepDestino'     => $cep,
            // Peso e valor no formato brasileiro, com vírgula ("12,34").
            'Peso'           => number_format(max($peso_kg, 0.01), 2, ',', ''),
            'ValorDeclarado' => number_format(max($valor_declarado, 1), 2, ',', ''),
            'TipoEntrega'    => 0, // 0 = entrega normal
            'Altura'         => $dims[2] ?? 0,
            'Largura'        => $dims[1] ?? 0,
            'Profundidade'   => $dims[0] ?? 0,
        ];

        /*
         * CACHE. Dois volumes iguais para o mesmo CEP são a mesma pergunta: só a
         * primeira vai à Total Express. A chave inclui tudo que muda o preço.
         * O usuário entra na chave para que trocar de credencial invalide o cache.
         * A versão entra para que "Limpar cache" funcione mesmo quando os
         * transients ficam no Redis/Memcached, e não na tabela de opções.
         */
        $versao = (int) get_option(self::OPCAO_VERSAO_CACHE, 1);
        $chave = 'cvte_' . md5(wp_json_encode($params) . '|' . $cred['usuario'] . '|' . $versao);
        if ($cfg['cache_horas'] > 0) {
            $guardado = get_transient($chave);
            if (is_array($guardado)) {
                $guardado['cache'] = true;
                return $guardado;
            }
        }

        $xml = self::montar_envelope($params);

        $resposta = wp_remote_post($cred['endpoint'], [
            'timeout' => $cfg['timeout'],
            'headers' => [
                'Content-Type'  => 'text/xml; charset=utf-8',
                'SOAPAction'    => '"urn:simulaFrete#calcularFrete"',
                'Authorization' => 'Basic ' . base64_encode($cred['usuario'] . ':' . $cred['senha']),
            ],
            'body'    => $xml,
        ]);

        if (is_wp_error($resposta)) {
            $r = self::falha('Falha de rede: ' . $resposta->get_error_message(), $xml);
            if ($guardar_falhas) {
                self::guardar_falha($chave, $r);
            }
            return $r;
        }

        $http = (int) wp_remote_retrieve_response_code($resposta);
        $corpo = (string) wp_remote_retrieve_body($resposta);

        if ($http === 401 || $http === 403) {
            // Credencial errada não é guardada em cache: corrigiu, funciona na hora.
            return self::falha("Acesso negado pela Total Express (HTTP $http). Confira usuário e senha.", $xml, $corpo);
        }

        $r = self::interpretar($corpo);
        $r['xml_enviado'] = $xml;
        $r['xml_recebido'] = $corpo;
        if (!$r['ok'] && $http !== 200 && $r['erro'] === 'Resposta sem CodigoProc.') {
            $r['erro'] = "Resposta inesperada (HTTP $http).";
        }

        if ($r['ok'] && $cfg['cache_horas'] > 0) {
            set_transient($chave, $r, $cfg['cache_horas'] * HOUR_IN_SECONDS);
        } elseif (!$r['ok'] && $guardar_falhas) {
            self::guardar_falha($chave, $r);
        }
        return $r;
    }

    /** Monta o envelope SOAP. Os valores são escapados para não quebrar o XML. */
    public static function montar_envelope(array $p): string
    {
        $tipos = [
            'TipoServico' => 'xsd:string', 'CepDestino' => 'xsd:nonNegativeInteger',
            'Peso' => 'xsd:string', 'ValorDeclarado' => 'xsd:string',
            'TipoEntrega' => 'xsd:nonNegativeInteger', 'Altura' => 'xsd:nonNegativeInteger',
            'Largura' => 'xsd:nonNegativeInteger', 'Profundidade' => 'xsd:nonNegativeInteger',
        ];
        $campos = '';
        foreach ($tipos as $nome => $tipo) {
            $valor = htmlspecialchars((string) $p[$nome], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $campos .= "<$nome xsi:type=\"$tipo\">$valor</$nome>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            . ' xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:urn="urn:calcularFrete">'
            . '<soapenv:Header/><soapenv:Body>'
            . '<urn:calcularFrete soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
            . '<calcularFreteRequest xsi:type="web:calcularFreteRequest"'
            . ' xmlns:web="http://edi.totalexpress.com.br/soap/webservice_calculo_frete.total">'
            . $campos
            . '</calcularFreteRequest></urn:calcularFrete></soapenv:Body></soapenv:Envelope>';
    }

    /**
     * Lê a resposta. Usamos expressões regulares tolerantes a prefixos de
     * namespace (<ns1:Prazo>, <Prazo>) em vez de um leitor XML estrito: a
     * Total Express pode mudar prefixos sem aviso, e isso não deve derrubar
     * o frete.
     */
    public static function interpretar(string $corpo): array
    {
        $tag = static function (string $nome) use ($corpo): ?string {
            if (preg_match('#<(?:[\w-]+:)?' . $nome . '\b[^>]*>(.*?)</(?:[\w-]+:)?' . $nome . '>#s', $corpo, $m)) {
                return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
            }
            return null;
        };

        if (($falha = $tag('faultstring')) !== null) {
            return self::falha('Erro SOAP: ' . $falha);
        }

        $codigo = $tag('CodigoProc');
        if ($codigo === null) {
            return self::falha('Resposta sem CodigoProc.');
        }
        if ((int) $codigo !== 1) {
            $erro = $tag('ErroConsultaFrete');
            return self::falha('Total Express recusou (CodigoProc ' . $codigo . ')' . ($erro ? ': ' . $erro : '.'));
        }

        $valor = self::para_numero($tag('ValorServico') ?? '');
        $prazo = (int) preg_replace('/\D/', '', $tag('Prazo') ?? '');

        if ($valor <= 0) {
            return self::falha('Resposta sem valor de frete.');
        }

        return ['ok' => true, 'valor' => $valor, 'prazo' => $prazo, 'erro' => '', 'cache' => false];
    }

    /**
     * Converte "1.234,56", "22,80" ou "22.80" em número.
     *
     * Cuidado clássico: tirar todos os pontos e trocar vírgula por ponto
     * transforma "22.80" em 2280. Aqui a regra olha qual separador vem por
     * último para decidir qual é o decimal.
     */
    public static function para_numero(string $texto): float
    {
        $t = preg_replace('/[^\d,.]/', '', $texto);
        if ($t === '') {
            return 0.0;
        }
        $ultima_virgula = strrpos($t, ',');
        $ultimo_ponto = strrpos($t, '.');

        if ($ultima_virgula !== false && ($ultimo_ponto === false || $ultima_virgula > $ultimo_ponto)) {
            $t = str_replace('.', '', $t);   // pontos eram milhar
            $t = str_replace(',', '.', $t);  // vírgula é o decimal
        } else {
            $t = str_replace(',', '', $t);   // vírgulas eram milhar
        }
        return (float) $t;
    }

    /**
     * Monta o resultado padrão de uma cotação que deu errado.
     *
     * Toda falha devolve o mesmo formato de um sucesso (com ok = false), para
     * que quem chama nunca precise testar se uma chave existe antes de lê-la.
     *
     * @param string $erro     explicação legível do problema (vai para o log)
     * @param string $enviado  XML que mandamos, quando houver (ajuda no diagnóstico)
     * @param string $recebido XML que voltou, quando houver
     */
    private static function falha(string $erro, string $enviado = '', string $recebido = ''): array
    {
        return [
            'ok' => false, 'valor' => 0.0, 'prazo' => 0, 'erro' => $erro, 'cache' => false,
            'xml_enviado' => $enviado, 'xml_recebido' => $recebido,
        ];
    }

    /**
     * Uma falha fica guardada por 5 minutos. Se a Total Express estiver fora do
     * ar, isso evita que cada visitante espere o timeout inteiro de novo.
     */
    private static function guardar_falha(string $chave, array $r): void
    {
        set_transient($chave, $r, 5 * MINUTE_IN_SECONDS);
    }
}
