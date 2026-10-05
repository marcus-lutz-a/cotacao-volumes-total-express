<?php
/**
 * Configurações do plugin: valores padrão, leitura e validação.
 *
 * As configurações ficam numa única opção do WordPress ("cvte_config"),
 * que vale para todas as áreas de entrega. Credenciais NÃO ficam nessa opção:
 * ficam no wp-config.php ou, criptografadas, numa opção própria (ver credenciais()).
 */

if (!defined('ABSPATH')) {
    exit;
}

class CVTE_Config
{
    const OPCAO = 'cvte_config';

    /** Endereço do webservice, conforme o manual de integração da Total Express. */
    const ENDPOINT_PADRAO = 'https://edi.totalexpress.com.br/webservice_calculo_frete.php';

    /**
     * Valores de fábrica. Cada um tem um comentário porque esta lista é,
     * na prática, a documentação das regras de negócio do frete.
     */
    public static function padroes(): array
    {
        return [
            // desligado | admin (só administradores veem) | sombra (calcula e
            // registra, ninguém vê) | ativo (todos os clientes veem)
            'modo'               => 'admin',

            // Código do serviço contratado: STD = Standard, EXP = Expresso.
            // Precisa ser o do contrato da loja; outro código é recusado.
            'servico'            => 'STD',

            // Simples Nacional sobre o faturamento, em %. 0 = não repassar.
            'simples_pct'        => 0,

            // dividir: frete ÷ (1 − %) — neutraliza o imposto de verdade.
            // multiplicar: frete × (1 + %) — a mesma regra da Frenet.
            'simples_modo'       => 'dividir',

            // Caixa e fita, cobrado por volume (2 volumes = 2 caixas). 0 = não cobrar.
            'embalagem_volume'   => 0,

            // Dias somados ao prazo da Total Express (tempo de separação).
            'prazo_adicional'    => 0,

            // Limites da Total Express por volume (tabela "Gen-Atend").
            'peso_max_volume'    => 30.0,
            'dim_max'            => [120, 80, 60], // cm, do maior para o menor lado

            // Medidas mínimas de um volume (cm). Uma caixa nunca é menor que isso.
            'dim_min'            => [20, 16, 10],

            // Espaço perdido numa caixa com 2 ou mais itens (%). Os itens nunca
            // encaixam perfeitamente; sem folga, a caixa estimada sai menor que
            // a real e o peso cubado (e o frete) sai subestimado.
            'folga_caixa_pct'    => 15,

            // Usado quando um produto está sem peso cadastrado (kg). O fato é
            // registrado no log para que o cadastro seja corrigido.
            'peso_padrao'        => 1.0,

            // Quanto tempo uma cotação idêntica é reaproveitada (horas).
            'cache_horas'        => 6,

            // Tempo máximo de espera pela Total Express, por consulta (segundos).
            'timeout'            => 6,

            // Tempo máximo somando as consultas de todos os volumes de um pedido
            // (segundos). Passou disso, a opção não aparece: o checkout não trava.
            'tempo_total'        => 10,

            // Texto mostrado ao cliente.
            'titulo'             => 'Total Express',
        ];
    }

    /** Lê a configuração salva, completando o que faltar com os padrões. */
    public static function obter(): array
    {
        $salvo = get_option(self::OPCAO, []);
        return array_merge(self::padroes(), is_array($salvo) ? $salvo : []);
    }

    /**
     * Valida o que veio do formulário. Tudo que chega do navegador é suspeito:
     * números viram números, textos são limpos, opções fora da lista são
     * trocadas pelo padrão.
     */
    public static function sanitizar(array $bruto): array
    {
        $p = self::padroes();

        /*
         * Função auxiliar: transforma o texto digitado em número.
         * Aceita vírgula ("6,7"), e se o valor for inválido usa o padrão.
         * Também prende o número entre um mínimo e um máximo, para que um
         * erro de digitação (ex.: 670 em vez de 6,70) não vire um frete absurdo.
         */
        $num = static function ($valor, $padrao, $min, $max) {
            $valor = is_string($valor) ? str_replace(',', '.', $valor) : $valor;
            if (!is_numeric($valor)) {
                return $padrao;
            }
            return max($min, min($max, (float) $valor));
        };

        $modo = $bruto['modo'] ?? $p['modo'];
        $servico = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($bruto['servico'] ?? $p['servico'])));
        $simples_modo = $bruto['simples_modo'] ?? $p['simples_modo'];

        return [
            'modo'             => in_array($modo, ['desligado', 'admin', 'sombra', 'ativo'], true) ? $modo : $p['modo'],
            'servico'          => $servico !== '' ? substr($servico, 0, 3) : $p['servico'],
            'simples_pct'      => $num($bruto['simples_pct'] ?? null, $p['simples_pct'], 0, 30),
            'simples_modo'     => in_array($simples_modo, ['dividir', 'multiplicar'], true) ? $simples_modo : $p['simples_modo'],
            'embalagem_volume' => $num($bruto['embalagem_volume'] ?? null, $p['embalagem_volume'], 0, 100),
            'prazo_adicional'  => (int) $num($bruto['prazo_adicional'] ?? null, $p['prazo_adicional'], 0, 30),
            'peso_max_volume'  => $num($bruto['peso_max_volume'] ?? null, $p['peso_max_volume'], 1, 30),
            'dim_max'          => $p['dim_max'], // limite contratual: não editável pela tela
            'dim_min'          => [
                (int) $num($bruto['dim_min_0'] ?? null, $p['dim_min'][0], 1, 120),
                (int) $num($bruto['dim_min_1'] ?? null, $p['dim_min'][1], 1, 80),
                (int) $num($bruto['dim_min_2'] ?? null, $p['dim_min'][2], 1, 60),
            ],
            'folga_caixa_pct'  => $num($bruto['folga_caixa_pct'] ?? null, $p['folga_caixa_pct'], 0, 100),
            'peso_padrao'      => $num($bruto['peso_padrao'] ?? null, $p['peso_padrao'], 0.01, 30),
            'cache_horas'      => (int) $num($bruto['cache_horas'] ?? null, $p['cache_horas'], 0, 72),
            'timeout'          => (int) $num($bruto['timeout'] ?? null, $p['timeout'], 2, 20),
            'tempo_total'      => (int) $num($bruto['tempo_total'] ?? null, $p['tempo_total'], 2, 30),
            'titulo'           => sanitize_text_field($bruto['titulo'] ?? $p['titulo']) ?: $p['titulo'],
        ];
    }

    /** Opção com as credenciais digitadas no painel (senha criptografada). */
    const OPCAO_CREDENCIAIS = 'cvte_credenciais';

    /**
     * Credenciais do webservice. Duas origens, nesta ordem:
     *
     *   1. constantes CVTE_USUARIO / CVTE_SENHA no wp-config.php;
     *   2. campos da tela do plugin, com a senha guardada CRIPTOGRAFADA no banco.
     *
     * Por que criptografar? O banco vai para backups, réplicas e exportações.
     * A chave da criptografia sai das chaves secretas do wp-config.php (que não
     * vai junto), então um backup do banco sozinho não revela a senha.
     *
     * @return array usuario, senha, endpoint, origem ('wp-config' | 'painel' |
     *   'ilegivel' quando a senha salva não pôde ser decifrada | 'nenhuma').
     */
    public static function credenciais(): array
    {
        $endpoint = defined('CVTE_ENDPOINT') ? (string) CVTE_ENDPOINT : self::ENDPOINT_PADRAO;

        if (defined('CVTE_USUARIO') && defined('CVTE_SENHA')) {
            return ['usuario' => (string) CVTE_USUARIO, 'senha' => (string) CVTE_SENHA, 'endpoint' => $endpoint, 'origem' => 'wp-config'];
        }
        return self::credenciais_salvas() + ['endpoint' => $endpoint];
    }

    /** Credenciais digitadas no painel, já decifradas. */
    public static function credenciais_salvas(): array
    {
        $salvo = get_option(self::OPCAO_CREDENCIAIS, []);
        if (!is_array($salvo) || empty($salvo['usuario']) || empty($salvo['senha'])) {
            return ['usuario' => '', 'senha' => '', 'origem' => 'nenhuma'];
        }
        $senha = self::decifrar((string) $salvo['senha']);
        if ($senha === null) {
            // As chaves do wp-config mudaram (ou o banco veio de outro site).
            return ['usuario' => (string) $salvo['usuario'], 'senha' => '', 'origem' => 'ilegivel'];
        }
        return ['usuario' => (string) $salvo['usuario'], 'senha' => $senha, 'origem' => 'painel'];
    }

    /**
     * Guarda as credenciais digitadas no painel.
     *
     * @param string $senha vazia = mantém a senha já guardada (o campo de senha
     *   nunca é preenchido de volta na tela).
     * @return string vazio se deu certo; senão, a explicação do problema.
     */
    public static function salvar_credenciais(string $usuario, string $senha): string
    {
        $usuario = trim($usuario);
        if ($usuario === '') {
            return 'Informe o usuário do webservice.';
        }
        if (self::chave() === null) {
            return 'As chaves secretas do WordPress (AUTH_KEY e SECURE_AUTH_KEY no wp-config.php) não estão definidas. Sem elas a senha não pode ser guardada com segurança.';
        }

        $atual = get_option(self::OPCAO_CREDENCIAIS, []);
        if ($senha === '') {
            if (!is_array($atual) || empty($atual['senha'])) {
                return 'Informe a senha do webservice.';
            }
            $cifrada = (string) $atual['senha'];
        } else {
            $cifrada = self::cifrar($senha);
        }

        update_option(self::OPCAO_CREDENCIAIS, ['usuario' => $usuario, 'senha' => $cifrada], false);
        return '';
    }

    public static function apagar_credenciais(): void
    {
        delete_option(self::OPCAO_CREDENCIAIS);
    }

    /**
     * Chave de 32 bytes derivada das chaves secretas do wp-config.php.
     * Recusa (null) se elas não existirem ou forem o texto de exemplo: nesse
     * caso o WordPress guarda chaves no BANCO, e a criptografia não protegeria nada.
     */
    public static function chave(): ?string
    {
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY'] as $c) {
            if (!defined($c) || strlen((string) constant($c)) < 32 || constant($c) === 'put your unique phrase here') {
                return null;
            }
        }
        return hash_hmac('sha256', 'cvte-credenciais-v1', AUTH_KEY . SECURE_AUTH_KEY, true);
    }

    /** Criptografa com libsodium (secretbox). Resultado: base64(nonce + texto cifrado). */
    public static function cifrar(string $texto): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($texto, $nonce, (string) self::chave()));
    }

    /** O inverso de cifrar(). Null se a chave mudou ou o dado foi adulterado. */
    public static function decifrar(string $guardado): ?string
    {
        $chave = self::chave();
        $bruto = base64_decode($guardado, true);
        if ($chave === null || $bruto === false || strlen($bruto) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $texto = sodium_crypto_secretbox_open(
            substr($bruto, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($bruto, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $chave
        );
        return $texto === false ? null : $texto;
    }

    public static function tem_credenciais(): bool
    {
        $c = self::credenciais();
        return $c['usuario'] !== '' && $c['senha'] !== '';
    }

    /**
     * Preço final ao cliente, a partir do custo da Total Express.
     *
     *   base  = soma do frete de cada volume + embalagem × nº de volumes
     *   final = base ÷ (1 − Simples)   [modo "dividir"]
     *         = base × (1 + Simples)   [modo "multiplicar"]
     *
     * Fica aqui, numa função pura (sem banco, sem rede), para poder ser
     * testada isoladamente.
     */
    public static function preco_final(array $fretes_volumes, array $cfg): float
    {
        $base = array_sum($fretes_volumes) + $cfg['embalagem_volume'] * count($fretes_volumes);
        $taxa = $cfg['simples_pct'] / 100;

        if ($cfg['simples_modo'] === 'multiplicar') {
            $final = $base * (1 + $taxa);
        } else {
            $final = $taxa < 1 ? $base / (1 - $taxa) : $base;
        }
        return round($final, 2);
    }
}
