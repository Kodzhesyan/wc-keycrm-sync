<?php
/**
 * Uninstall WooCommerce KeyCRM Integration
 *
 * @package WC_KeyCRM_Sync
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('wc_keycrm_api_key');
delete_option('wc_keycrm_source_id');
delete_option('wc_keycrm_debug_mode');
delete_option('wc_keycrm_shipping_mappings');
delete_option('wc_keycrm_payment_mappings');

// Delete sync status from all orders
global $wpdb;
$wpdb->delete(
    $wpdb->postmeta,
    array('meta_key' => '_keycrm_synced'),
    array('%s')
);