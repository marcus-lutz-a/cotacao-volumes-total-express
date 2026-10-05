<?php
/**
 * Executado pelo WordPress quando o plugin é EXCLUÍDO (não ao desativar).
 * Remove as configurações, as credenciais digitadas no painel e as cotações
 * guardadas. Constantes no wp-config.php, se houver, não são tocadas.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('cvte_config');
delete_option('cvte_cache_versao');
delete_option('cvte_credenciais');

global $wpdb;
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like('_transient_cvte_') . '%',
    $wpdb->esc_like('_transient_timeout_cvte_') . '%'
));
