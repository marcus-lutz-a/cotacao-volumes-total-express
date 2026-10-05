<?php
/**
 * Plugin Name:       Cotação por Volumes para Total Express
 * Description:       Cota o frete da Total Express direto no webservice da transportadora, com o
 *                    contrato da própria loja, dividindo o pedido em volumes de até 30 kg.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Marcus Lutz
 * Text Domain:       cotacao-volumes-total-express
 * License:           GPL-2.0-or-later
 *
 * WC requires at least: 8.0
 *
 * Projeto independente, sem vínculo com a Total Express.
 *
 * ---------------------------------------------------------------------------
 * COMO O PLUGIN ESTÁ ORGANIZADO
 * ---------------------------------------------------------------------------
 *   cotacao-volumes-total-express.php    este arquivo: carrega as peças e registra o método
 *   includes/class-cvte-config.php       lê e valida as configurações
 *   includes/class-cvte-api.php          conversa com o webservice (SOAP)
 *   includes/class-cvte-empacotador.php  divide o carrinho em volumes
 *   includes/class-cvte-metodo.php       o método de entrega do WooCommerce
 *   includes/class-cvte-admin.php        a tela de configuração e de teste
 *
 * Cada classe faz uma coisa só. Assim dá para testar o empacotador sem
 * internet, e trocar a API sem mexer no cálculo do preço.
 */

if (!defined('ABSPATH')) {
    exit; // Acesso direto ao arquivo pelo navegador: não faz nada.
}

define('CVTE_VERSAO', '0.2.0');
define('CVTE_DIR', plugin_dir_path(__FILE__));

/*
 * HPOS (tabelas novas de pedidos do WooCommerce): este plugin não lê nem grava
 * pedidos, então é compatível. Declarar evita o aviso amarelo no painel.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/*
 * As classes só são carregadas depois que todos os plugins subiram, porque o
 * método de entrega herda de WC_Shipping_Method — que só existe se o
 * WooCommerce estiver ativo.
 */
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return; // Sem WooCommerce, o plugin fica quieto em vez de quebrar o site.
    }

    require_once CVTE_DIR . 'includes/class-cvte-config.php';
    require_once CVTE_DIR . 'includes/class-cvte-api.php';
    require_once CVTE_DIR . 'includes/class-cvte-empacotador.php';
    require_once CVTE_DIR . 'includes/class-cvte-metodo.php';

    // Registra o método na lista de "métodos de entrega" das áreas de entrega.
    add_filter('woocommerce_shipping_methods', function ($metodos) {
        $metodos['cvte_total_express'] = 'CVTE_Metodo';
        return $metodos;
    });

    if (is_admin()) {
        require_once CVTE_DIR . 'includes/class-cvte-admin.php';
        CVTE_Admin::iniciar();
    }
}, 20);
