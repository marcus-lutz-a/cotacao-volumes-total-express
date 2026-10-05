<?php
/**
 * A tela "WooCommerce → Total Express" no painel.
 *
 * Três blocos:
 *   1. Situação   — credenciais presentes? qual modo? em quais áreas está?
 *   2. Regras     — Simples, embalagem, prazo, modo de exibição...
 *   3. Ferramentas — testar uma cotação avulsa e adicionar o método às áreas.
 *
 * ---------------------------------------------------------------------------
 * SEGURANÇA DOS FORMULÁRIOS
 * ---------------------------------------------------------------------------
 * Toda ação passa por três portas antes de fazer qualquer coisa:
 *   - permissão: o usuário precisa poder administrar a loja;
 *   - nonce: um código de uso único que prova que o formulário saiu desta
 *     tela, e não de um site malicioso tentando agir em seu nome (CSRF);
 *   - sanitização: todo valor recebido é limpo antes de ser usado.
 *
 * Depois de processar, a tela redireciona para si mesma (padrão
 * "Post/Redirect/Get"): assim, apertar F5 não repete a ação.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CVTE_Admin
{
    const PAGINA = 'cvte-total-express';
    const NONCE  = 'cvte_admin';

    /** Registra o menu e o processamento dos formulários. */
    public static function iniciar(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'processar']);
    }

    /** Adiciona "Total Express" dentro do menu WooCommerce. */
    public static function menu(): void
    {
        add_submenu_page(
            'woocommerce',
            'Cotação por Volumes para Total Express',
            'Total Express',
            'manage_woocommerce',
            self::PAGINA,
            [self::class, 'renderizar']
        );
    }

    /**
     * Recebe os formulários desta tela. Não faz nada se o envio não veio daqui.
     */
    public static function processar(): void
    {
        if (empty($_POST['cvte_acao'])) {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão.');
        }
        check_admin_referer(self::NONCE); // morre aqui se o nonce for inválido

        $acao = sanitize_key($_POST['cvte_acao']);
        $dados = wp_unslash($_POST); // o WordPress adiciona barras; removemos

        switch ($acao) {
            case 'salvar':
                update_option(CVTE_Config::OPCAO, CVTE_Config::sanitizar($dados), false);
                self::avisar('Configurações salvas.');
                break;

            case 'salvar_credenciais':
                // A senha não passa por sanitize_text_field: ele apagaria
                // caracteres válidos numa senha (<, %, espaços). Ela nunca é
                // exibida nem vai para SQL ou HTML — só é criptografada.
                $erro = CVTE_Config::salvar_credenciais(
                    sanitize_text_field((string) ($dados['cred_usuario'] ?? '')),
                    substr((string) ($dados['cred_senha'] ?? ''), 0, 200)
                );
                self::avisar($erro ?: 'Credenciais salvas (senha criptografada).', $erro ? 'error' : 'success');
                break;

            case 'apagar_credenciais':
                CVTE_Config::apagar_credenciais();
                self::avisar('Credenciais apagadas.');
                break;

            case 'testar':
                self::guardar_teste(self::executar_teste($dados));
                break;

            case 'adicionar_areas':
                $n = self::adicionar_nas_areas();
                self::avisar($n ? "Método adicionado a $n área(s) de entrega." : 'Nenhuma área nova: o método já está em todas as áreas que têm a Frenet (ou nenhuma área tem a Frenet).');
                break;

            case 'limpar_cache':
                $n = self::limpar_cache();
                self::avisar("Cache de cotações limpo (todas as cotações invalidadas; $n registro(s) apagados da tabela de opções).");
                break;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGINA));
        exit;
    }

    /**
     * Faz uma cotação avulsa com os valores do formulário de teste.
     * Ignora o cache de propósito: o objetivo é ver a resposta real agora.
     * E não guarda a falha: um teste com erro não pode esconder a Total
     * Express dos clientes que coincidirem com os mesmos CEP e medidas.
     *
     * @param array $d dados do formulário.
     * @return array entrada e resultado, para exibir na tela.
     */
    private static function executar_teste(array $d): array
    {
        $cfg = CVTE_Config::obter();
        $cfg['cache_horas'] = 0;
        if (!empty($d['t_servico'])) {
            $cfg['servico'] = substr(strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $d['t_servico'])), 0, 3);
        }

        $num = static fn($k, $padrao) => is_numeric(str_replace(',', '.', $d[$k] ?? '')) ? (float) str_replace(',', '.', $d[$k]) : $padrao;
        $entrada = [
            'cep'   => preg_replace('/\D/', '', $d['t_cep'] ?? ''),
            'peso'  => $num('t_peso', 1.0),
            'valor' => $num('t_valor', 50.0),
            'dims'  => [$num('t_comp', 20), $num('t_larg', 16), $num('t_alt', 10)],
            'servico' => $cfg['servico'],
        ];

        $inicio = microtime(true);
        $r = CVTE_API::cotar($entrada['cep'], $entrada['peso'], $entrada['valor'], $entrada['dims'], $cfg, false);
        $r['ms'] = (int) round((microtime(true) - $inicio) * 1000);
        if ($r['ok']) {
            $r['preco_cliente'] = CVTE_Config::preco_final([$r['valor']], $cfg);
        }
        return ['entrada' => $entrada, 'resultado' => $r];
    }

    /**
     * Migração da Frenet: adiciona este método a todas as áreas de entrega que
     * já têm a Frenet e
     * ainda não têm este. Assim o teste lado a lado cobre exatamente as mesmas
     * regiões, sem configurar 10 áreas à mão.
     *
     * @return int quantas áreas receberam o método.
     */
    private static function adicionar_nas_areas(): int
    {
        $adicionadas = 0;
        $areas = WC_Shipping_Zones::get_zones();
        $areas[] = ['zone_id' => 0]; // a área "Locais não cobertos" também conta

        foreach ($areas as $dados) {
            $area = new WC_Shipping_Zone($dados['zone_id']);
            $ids = array_map(static fn($m) => $m->id, $area->get_shipping_methods());

            if (in_array('frenet', $ids, true) && !in_array('cvte_total_express', $ids, true)) {
                // O WooCommerce devolve 0, sem erro, quando recusa a inclusão:
                // só conta o que foi de fato adicionado.
                if ($area->add_shipping_method('cvte_total_express')) {
                    $adicionadas++;
                }
            }
        }
        return $adicionadas;
    }

    /**
     * Apaga as cotações guardadas (útil depois de trocar a credencial ou o
     * serviço contratado).
     *
     * Duas etapas: (1) sobe a versão do cache, o que invalida tudo em
     * qualquer lugar onde os transients estejam (tabela de opções, Redis,
     * Memcached); (2) apaga as linhas antigas da tabela de opções, quando
     * estão lá, para não ocupar espaço.
     *
     * @return int quantos registros foram apagados da tabela de opções.
     */
    private static function limpar_cache(): int
    {
        global $wpdb;
        update_option(CVTE_API::OPCAO_VERSAO_CACHE, (int) get_option(CVTE_API::OPCAO_VERSAO_CACHE, 1) + 1, true);

        // Transients ficam na tabela de opções com o prefixo _transient_.
        // esc_like + prepare: nunca montamos SQL com texto solto.
        $padrao = $wpdb->esc_like('_transient_cvte_') . '%';
        $padrao_t = $wpdb->esc_like('_transient_timeout_cvte_') . '%';
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $padrao,
            $padrao_t
        ));
    }

    /**
     * Lista as áreas de entrega e diz em quais o método está presente.
     *
     * @return array [ ['nome' => string, 'frenet' => bool, 'plugin' => bool] ]
     */
    private static function situacao_areas(): array
    {
        $lista = [];
        $areas = WC_Shipping_Zones::get_zones();
        $areas[] = ['zone_id' => 0];
        foreach ($areas as $dados) {
            $area = new WC_Shipping_Zone($dados['zone_id']);
            $ids = array_map(static fn($m) => $m->id, $area->get_shipping_methods(true)); // só os ativos
            $lista[] = [
                'nome'   => $area->get_zone_name(),
                'frenet' => in_array('frenet', $ids, true),
                'plugin' => in_array('cvte_total_express', $ids, true),
            ];
        }
        return $lista;
    }

    /* ------------------------------------------------------------------------
     * Mensagens entre o processamento e a tela (sobrevivem ao redirecionamento)
     * --------------------------------------------------------------------- */

    /**
     * Guarda uma mensagem para a próxima exibição da tela deste usuário.
     *
     * @param string $tipo 'success' ou 'error' (a cor do aviso).
     */
    private static function avisar(string $texto, string $tipo = 'success'): void
    {
        set_transient('cvte_aviso_' . get_current_user_id(), ['texto' => $texto, 'tipo' => $tipo], 60);
    }

    /** Guarda o resultado de um teste para a próxima exibição da tela. */
    private static function guardar_teste(array $teste): void
    {
        set_transient('cvte_teste_' . get_current_user_id(), $teste, 300);
    }

    /** Lê e apaga uma mensagem guardada (ela só aparece uma vez). */
    private static function consumir(string $prefixo)
    {
        $chave = $prefixo . get_current_user_id();
        $valor = get_transient($chave);
        delete_transient($chave);
        return $valor;
    }

    /* ------------------------------------------------------------------------
     * A tela
     * --------------------------------------------------------------------- */

    /**
     * Desenha a página. Todo texto vindo de dados passa por esc_html/esc_attr:
     * é o que impede que um valor malicioso vire código na tela (XSS).
     */
    public static function renderizar(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $cfg = CVTE_Config::obter();
        $cred = CVTE_Config::credenciais();
        $aviso = self::consumir('cvte_aviso_');
        $teste = self::consumir('cvte_teste_');
        $modos = [
            'desligado' => 'Desligado',
            'admin'     => 'Só administradores veem (teste lado a lado)',
            'sombra'    => 'Sombra: calcula e registra no log, ninguém vê',
            'ativo'     => 'Ativo: todos os clientes veem',
        ];
        $campo = static function (string $nome, $valor, string $passo = '0.01') {
            printf('<input type="number" step="%s" name="%s" value="%s" class="small-text">', esc_attr($passo), esc_attr($nome), esc_attr($valor));
        };
        ?>
        <div class="wrap">
            <h1>Cotação por Volumes para Total Express <small style="font-weight:400">v<?php echo esc_html(CVTE_VERSAO); ?></small></h1>

            <?php if ($aviso) : $aviso = is_array($aviso) ? $aviso : ['texto' => (string) $aviso, 'tipo' => 'success']; ?>
                <div class="notice notice-<?php echo $aviso['tipo'] === 'error' ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html($aviso['texto']); ?></p></div>
            <?php endif; ?>

            <h2>1. Situação</h2>
            <table class="widefat striped" style="max-width:820px">
                <tr><th style="width:240px">Credenciais</th>
                    <td><?php
                        $situacoes = [
                            'wp-config' => '✅ Definidas no wp-config.php',
                            'painel'    => '✅ Definidas nesta tela (senha criptografada)',
                            'ilegivel'  => '❌ A senha salva não pôde ser lida — as chaves secretas do WordPress mudaram. Digite-a de novo abaixo.',
                            'nenhuma'   => '❌ Não definidas — preencha abaixo',
                        ];
                        echo esc_html($situacoes[$cred['origem']] ?? $cred['origem']);
                    ?></td></tr>
                <tr><th>Endereço do webservice</th><td><code><?php echo esc_html($cred['endpoint']); ?></code></td></tr>
                <tr><th>Modo atual</th><td><strong><?php echo esc_html($modos[$cfg['modo']] ?? $cfg['modo']); ?></strong></td></tr>
                <tr><th>Registros (log)</th>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=logs')); ?>">WooCommerce → Status → Logs</a>, origem <code>cvte-total-express</code></td></tr>
            </table>

            <h3>Credenciais do webservice</h3>
            <?php if ($cred['origem'] === 'wp-config') : ?>
                <p>Em uso: as constantes do <code>wp-config.php</code>. Elas têm prioridade sobre esta tela.</p>
            <?php else : ?>
                <?php $salvas = CVTE_Config::credenciais_salvas(); ?>
                <form method="post" autocomplete="off" style="max-width:820px">
                    <?php wp_nonce_field(self::NONCE); ?>
                    <input type="hidden" name="cvte_acao" value="salvar_credenciais">
                    <table class="form-table">
                        <tr><th><label for="cvte-usuario">Usuário</label></th>
                            <td><input type="text" id="cvte-usuario" name="cred_usuario" value="<?php echo esc_attr($salvas['usuario']); ?>" class="regular-text" autocomplete="off"></td></tr>
                        <tr><th><label for="cvte-senha">Senha</label></th>
                            <td><input type="password" id="cvte-senha" name="cred_senha" value="" class="regular-text" autocomplete="new-password"
                                placeholder="<?php echo $salvas['origem'] === 'painel' ? esc_attr('•••••••• (deixe em branco para manter)') : ''; ?>">
                            <p class="description">A senha é guardada criptografada, com uma chave tirada do <code>wp-config.php</code>, e nunca volta para a tela.</p></td></tr>
                    </table>
                    <?php submit_button('Salvar credenciais', 'primary', 'submit', false); ?>
                </form>
                <?php if ($salvas['origem'] !== 'nenhuma') : ?>
                    <form method="post" style="margin-top:8px">
                        <?php wp_nonce_field(self::NONCE); ?>
                        <input type="hidden" name="cvte_acao" value="apagar_credenciais">
                        <?php submit_button('Apagar credenciais salvas', 'delete', 'submit', false, ['onclick' => "return confirm('Apagar as credenciais? A Total Express deixa de cotar até que sejam digitadas de novo.');"]); ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <h3>Áreas de entrega</h3>
            <p class="description" style="max-width:820px">A coluna Frenet ajuda na migração: o botão abaixo coloca este método em todas as áreas que já têm a Frenet, para comparar os dois lado a lado antes de desativá-la.</p>
            <table class="widefat striped" style="max-width:820px">
                <thead><tr><th>Área</th><th>Frenet</th><th>Este plugin</th></tr></thead>
                <?php foreach (self::situacao_areas() as $a) : ?>
                    <tr><td><?php echo esc_html($a['nome']); ?></td>
                        <td><?php echo $a['frenet'] ? '✅' : '—'; ?></td>
                        <td><?php echo $a['plugin'] ? '✅' : '—'; ?></td></tr>
                <?php endforeach; ?>
            </table>
            <form method="post" style="margin-top:8px">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="cvte_acao" value="adicionar_areas">
                <?php submit_button('Adicionar este método às áreas que têm a Frenet', 'secondary', 'submit', false); ?>
            </form>

            <h2>2. Regras de preço e exibição</h2>
            <form method="post">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="cvte_acao" value="salvar">
                <table class="form-table">
                    <tr><th>Modo</th><td><select name="modo">
                        <?php foreach ($modos as $valor => $rotulo) : ?>
                            <option value="<?php echo esc_attr($valor); ?>" <?php selected($cfg['modo'], $valor); ?>><?php echo esc_html($rotulo); ?></option>
                        <?php endforeach; ?>
                    </select></td></tr>
                    <tr><th>Nome mostrado ao cliente</th>
                        <td><input type="text" name="titulo" value="<?php echo esc_attr($cfg['titulo']); ?>" class="regular-text"></td></tr>
                    <tr><th>Serviço contratado</th>
                        <td><input type="text" name="servico" value="<?php echo esc_attr($cfg['servico']); ?>" class="small-text" maxlength="3">
                        <p class="description">STD = Standard · EXP = Expresso. Use o código que consta no seu contrato com a Total Express (um código não contratado é recusado com "Tipo de Servico nao utilizado").</p></td></tr>
                    <tr><th>Simples Nacional (%)</th>
                        <td><?php $campo('simples_pct', $cfg['simples_pct']); ?>
                        <select name="simples_modo">
                            <option value="dividir" <?php selected($cfg['simples_modo'], 'dividir'); ?>>dividir: frete ÷ (1 − %) — neutraliza o imposto</option>
                            <option value="multiplicar" <?php selected($cfg['simples_modo'], 'multiplicar'); ?>>multiplicar: frete × (1 + %) — mesma conta usada pela Frenet</option>
                        </select></td></tr>
                    <tr><th>Embalagem por volume (R$)</th><td><?php $campo('embalagem_volume', $cfg['embalagem_volume']); ?></td></tr>
                    <tr><th>Prazo adicional (dias úteis)</th><td><?php $campo('prazo_adicional', $cfg['prazo_adicional'], '1'); ?></td></tr>
                    <tr><th>Peso máximo por volume (kg)</th><td><?php $campo('peso_max_volume', $cfg['peso_max_volume'], '0.1'); ?></td></tr>
                    <tr><th>Caixa mínima (cm)</th>
                        <td><?php $campo('dim_min_0', $cfg['dim_min'][0], '1'); ?> × <?php $campo('dim_min_1', $cfg['dim_min'][1], '1'); ?> × <?php $campo('dim_min_2', $cfg['dim_min'][2], '1'); ?></td></tr>
                    <tr><th>Folga da caixa com 2+ itens (%)</th>
                        <td><?php $campo('folga_caixa_pct', $cfg['folga_caixa_pct'], '1'); ?>
                        <p class="description">Espaço perdido entre os itens. Calibre comparando com as medidas das DACTEs reais.</p></td></tr>
                    <tr><th>Peso para produto sem peso (kg)</th><td><?php $campo('peso_padrao', $cfg['peso_padrao']); ?></td></tr>
                    <tr><th>Guardar cotações por (horas)</th><td><?php $campo('cache_horas', $cfg['cache_horas'], '1'); ?></td></tr>
                    <tr><th>Esperar a Total Express até (s)</th><td><?php $campo('timeout', $cfg['timeout'], '1'); ?> por consulta</td></tr>
                    <tr><th>Tempo total por pedido (s)</th>
                        <td><?php $campo('tempo_total', $cfg['tempo_total'], '1'); ?>
                        <p class="description">Soma das consultas de todos os volumes. Passou disso, a opção não aparece e o checkout segue.</p></td></tr>
                </table>
                <?php submit_button('Salvar regras'); ?>
            </form>

            <h2>3. Testar uma cotação</h2>
            <p>Consulta a Total Express agora, sem cache. Útil para validar a credencial e comparar com o frete que a loja usa hoje.</p>
            <form method="post">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="cvte_acao" value="testar">
                CEP <input type="text" name="t_cep" value="<?php echo esc_attr($teste['entrada']['cep'] ?? ''); ?>" class="small-text" style="width:90px">
                Peso (kg) <?php $campo('t_peso', $teste['entrada']['peso'] ?? 10); ?>
                Valor (R$) <?php $campo('t_valor', $teste['entrada']['valor'] ?? 150); ?>
                Medidas (cm) <?php $campo('t_comp', $teste['entrada']['dims'][0] ?? 40, '1'); ?>
                × <?php $campo('t_larg', $teste['entrada']['dims'][1] ?? 30, '1'); ?>
                × <?php $campo('t_alt', $teste['entrada']['dims'][2] ?? 20, '1'); ?>
                Serviço <input type="text" name="t_servico" value="<?php echo esc_attr($teste['entrada']['servico'] ?? $cfg['servico']); ?>" class="small-text" maxlength="3">
                <?php submit_button('Consultar', 'primary', 'submit', false); ?>
            </form>

            <?php if ($teste) : $r = $teste['resultado']; ?>
                <div class="notice <?php echo $r['ok'] ? 'notice-success' : 'notice-error'; ?>" style="max-width:820px">
                    <p><?php if ($r['ok']) : ?>
                        <strong>Custo Total Express: R$ <?php echo esc_html(number_format($r['valor'], 2, ',', '.')); ?></strong>
                        · prazo <?php echo (int) $r['prazo']; ?> dias úteis
                        · preço ao cliente (1 volume, com embalagem e Simples): <strong>R$ <?php echo esc_html(number_format($r['preco_cliente'], 2, ',', '.')); ?></strong>
                    <?php else : ?>
                        <strong>Sem cotação:</strong> <?php echo esc_html($r['erro']); ?>
                    <?php endif; ?>
                    · <?php echo (int) $r['ms']; ?> ms</p>
                </div>
                <details style="max-width:820px"><summary>Ver XML enviado e recebido</summary>
                    <p>Enviado (a senha não aparece aqui: ela vai no cabeçalho HTTP, não no XML):</p>
                    <pre style="white-space:pre-wrap;background:#fff;padding:8px"><?php echo esc_html($r['xml_enviado'] ?? ''); ?></pre>
                    <p>Recebido:</p>
                    <pre style="white-space:pre-wrap;background:#fff;padding:8px"><?php echo esc_html($r['xml_recebido'] ?? ''); ?></pre>
                </details>
            <?php endif; ?>

            <form method="post" style="margin-top:16px">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="cvte_acao" value="limpar_cache">
                <?php submit_button('Limpar cache de cotações', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }
}
