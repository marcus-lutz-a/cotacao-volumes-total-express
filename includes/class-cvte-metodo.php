<?php
/**
 * O método de entrega "Total Express" que aparece nas áreas de entrega do
 * WooCommerce.
 *
 * ---------------------------------------------------------------------------
 * COMO O WOOCOMMERCE CALCULA FRETE (o mínimo para entender este arquivo)
 * ---------------------------------------------------------------------------
 * 1. O cliente informa o CEP (no carrinho, no checkout ou na página do produto).
 * 2. O WooCommerce descobre em qual "área de entrega" o CEP cai.
 * 3. Para cada método daquela área, chama calculate_shipping($pacote).
 * 4. Cada método que quiser aparecer chama $this->add_rate(...) com um preço.
 *
 * Este arquivo é só a "cola" entre o WooCommerce e as outras peças:
 *   pacote do carrinho → CVTE_Empacotador → CVTE_API (um volume por vez)
 *   → CVTE_Config::preco_final → add_rate()
 */

if (!defined('ABSPATH')) {
    exit;
}

class CVTE_Metodo extends WC_Shipping_Method
{
    /** Origem dos registros em WooCommerce → Status → Logs. */
    const LOG = 'cvte-total-express';

    /**
     * Construtor: o WooCommerce cria uma instância por área de entrega.
     *
     * @param int $instance_id identificador desta cópia do método numa área.
     */
    public function __construct($instance_id = 0)
    {
        $this->id                 = 'cvte_total_express';
        $this->instance_id        = absint($instance_id);
        $this->method_title       = 'Total Express (cotação por volumes)';
        $this->method_description = 'Cotação direta no webservice da Total Express, com divisão em volumes de até 30 kg. '
                                  . 'As regras de preço ficam em WooCommerce → Total Express.';

        // "shipping-zones": pode ser adicionado às áreas de entrega.
        // "instance-settings-modal": as opções abrem numa janela dentro da área.
        $this->supports = ['shipping-zones', 'instance-settings', 'instance-settings-modal'];

        $this->init();
    }

    /**
     * Carrega as opções da instância. Elas são poucas de propósito: as regras de
     * preço são globais (tela própria), para não precisar repetir em 10 áreas.
     */
    public function init(): void
    {
        $this->instance_form_fields = [
            'enabled' => [
                'title'   => 'Ativar nesta área',
                'type'    => 'checkbox',
                'default' => 'yes',
            ],
        ];
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->title   = CVTE_Config::obter()['titulo'];

        add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
    }

    /**
     * O método deve ser oferecido nesta situação?
     *
     * É aqui que o "modo" da configuração faz efeito:
     *   desligado → nunca
     *   admin     → só para quem administra a loja (teste lado a lado com o frete atual)
     *   sombra    → calcula e registra no log, mas não mostra (decidido em calculate_shipping)
     *   ativo     → para todos
     *
     * @param array $pacote o pacote do carrinho.
     */
    public function is_available($pacote): bool
    {
        $modo = CVTE_Config::obter()['modo'];

        if ($modo === 'desligado' || $this->enabled !== 'yes') {
            return false;
        }
        if ($modo === 'admin' && !current_user_can('manage_woocommerce')) {
            return false;
        }
        // Total Express só entrega no Brasil.
        return ($pacote['destination']['country'] ?? 'BR') === 'BR'
            && parent::is_available($pacote);
    }

    /**
     * O coração do método: transforma o carrinho num preço.
     *
     * Qualquer problema (credencial, rede, produto grande demais) faz o método
     * simplesmente NÃO aparecer. Os outros fretes (PAC, SEDEX, retirada)
     * continuam disponíveis, e o motivo fica registrado no log.
     *
     * @param array $pacote o pacote do carrinho: itens, destino, valores.
     */
    public function calculate_shipping($pacote = []): void
    {
        $cfg = CVTE_Config::obter();
        $cep = preg_replace('/\D/', '', $pacote['destination']['postcode'] ?? '');

        if (strlen($cep) !== 8) {
            return; // Ainda sem CEP completo: nada a calcular.
        }

        // 1. Carrinho → lista de itens com peso, medidas e valor.
        $itens = $this->itens_do_pacote($pacote, $cfg);

        // 2. Itens → volumes de até 30 kg.
        $emb = CVTE_Empacotador::empacotar($itens, $cfg);
        if (!$emb['ok']) {
            $this->log("CEP $cep: indisponível — " . $emb['erro']);
            return;
        }

        // 3. Uma cotação por volume. Volumes idênticos saem do cache.
        //    As consultas dividem um tempo total: com 4 volumes e a Total
        //    Express lenta, o cliente esperaria 4 × timeout. Cada consulta
        //    recebe o menor entre o seu timeout e o tempo que ainda resta.
        $limite = microtime(true) + (int) $cfg['tempo_total'];
        $fretes = [];
        $prazo = 0;
        $sem_prazo = false;
        foreach ($emb['volumes'] as $n => $vol) {
            $restante = (int) floor($limite - microtime(true));
            if ($restante < 1) {
                $this->log(sprintf('CEP %s: tempo total de %d s esgotado no volume %d de %d.', $cep, $cfg['tempo_total'], $n + 1, count($emb['volumes'])));
                return;
            }
            $cfg_volume = $cfg;
            $cfg_volume['timeout'] = min((int) $cfg['timeout'], $restante);
            $encurtado = $cfg_volume['timeout'] < (int) $cfg['timeout'];

            $r = CVTE_API::cotar($cep, $vol['peso'], $vol['valor'], $vol['dims'], $cfg_volume, !$encurtado);
            if (!$r['ok']) {
                $this->log(sprintf('CEP %s: volume %d (%.2f kg) sem cotação — %s', $cep, $n + 1, $vol['peso'], $r['erro']));
                return; // Sem todos os volumes cotados, não há preço confiável.
            }
            $fretes[] = $r['valor'];
            $prazo = max($prazo, $r['prazo']); // o pedido chega quando chega o último volume
            $sem_prazo = $sem_prazo || $r['prazo'] <= 0;
        }

        // 4. Custo da Total Express → preço ao cliente (embalagem + Simples).
        $preco = CVTE_Config::preco_final($fretes, $cfg);
        $prazo_total = $prazo + (int) $cfg['prazo_adicional'];

        $this->log(sprintf(
            'CEP %s [%s]: %d volume(s) %s → custo TEX R$ %s → cliente R$ %s, %s',
            $cep,
            $cfg['modo'],
            count($fretes),
            wp_json_encode(array_map(static fn($v) => [$v['peso'], $v['dims']], $emb['volumes'])),
            number_format(array_sum($fretes), 2, ',', '.'),
            number_format($preco, 2, ',', '.'),
            $sem_prazo ? 'SEM PRAZO na resposta da Total Express' : $prazo_total . ' dias'
        ));

        // Modo sombra: o cálculo acima foi registrado; o cliente não vê nada.
        if ($cfg['modo'] === 'sombra') {
            return;
        }

        // Sem prazo em algum volume, não mostramos um número inventado
        // (seria só o prazo adicional, ex.: "1 dia útil").
        $rotulo = $sem_prazo
            ? $this->title
            : sprintf('%s (Entrega em %d dias úteis)', $this->title, $prazo_total);
        if ($cfg['modo'] === 'admin') {
            $rotulo = '[TESTE] ' . $rotulo; // impossível confundir com o frete atual
        }

        $meta = ['Volumes' => count($fretes), 'Serviço' => $cfg['servico']];
        if (!$sem_prazo) {
            $meta['Prazo (dias úteis)'] = $prazo_total;
        }

        $this->add_rate([
            'id'        => $this->get_rate_id(),
            'label'     => $rotulo,
            'cost'      => $preco,
            'meta_data' => $meta,
        ]);
    }

    /**
     * Converte os itens do carrinho no formato que o empacotador entende.
     *
     * Detalhes que importam:
     *  - peso e medidas são convertidos para kg e cm, seja qual for a unidade
     *    configurada na loja;
     *  - produto sem peso usa o "peso padrão" e gera um aviso no log, para que
     *    o cadastro seja corrigido;
     *  - o valor declarado é o preço pago pelo item (com desconto), que é o
     *    que vai na nota fiscal e define o seguro.
     *
     * @param array $pacote pacote do carrinho.
     * @param array $cfg    configuração.
     * @return array lista de itens para CVTE_Empacotador::empacotar().
     */
    private function itens_do_pacote(array $pacote, array $cfg): array
    {
        $itens = [];
        foreach ($pacote['contents'] as $linha) {
            /** @var WC_Product $produto */
            $produto = $linha['data'];
            if (!$produto || !$produto->needs_shipping()) {
                continue; // produtos virtuais (cursos, vales) não vão na caixa
            }

            $qtd = max(1, (int) $linha['quantity']);
            $peso = (float) wc_get_weight((float) $produto->get_weight(), 'kg');
            if ($peso <= 0) {
                $peso = (float) $cfg['peso_padrao'];
                $this->log(sprintf('Produto sem peso: "%s" (ID %d) — usando %.2f kg.', $produto->get_name(), $produto->get_id(), $peso));
            }

            $dims = [
                (float) wc_get_dimension((float) $produto->get_length(), 'cm'),
                (float) wc_get_dimension((float) $produto->get_width(), 'cm'),
                (float) wc_get_dimension((float) $produto->get_height(), 'cm'),
            ];
            // Medida em branco vira 1 cm: a caixa mínima cobre o resto.
            $dims = array_map(static fn($d) => $d > 0 ? $d : 1.0, $dims);

            $itens[] = [
                'nome'  => $produto->get_name(),
                'peso'  => $peso,
                'dims'  => $dims,
                'valor' => (float) $linha['line_total'] / $qtd,
                'qtd'   => $qtd,
            ];
        }
        return $itens;
    }

    /**
     * Registra uma linha em WooCommerce → Status → Logs → cvte-total-express.
     *
     * @param string $mensagem texto a registrar.
     */
    private function log(string $mensagem): void
    {
        wc_get_logger()->info($mensagem, ['source' => self::LOG]);
    }
}
