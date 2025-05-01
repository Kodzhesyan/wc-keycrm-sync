<?php
/**
 * Plugin Name: WooCommerce KeyCRM Integration
 * Plugin URI: https://example.com/plugins/wc-keycrm-sync
 * Description: Syncs WooCommerce orders with KeyCRM
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Roman Kodzhesyan
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-keycrm-sync
 * Domain Path: /languages
 * WC requires at least: 6.0
 * WC tested up to: 8.0
 *
 * @package WC_KeyCRM_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_KEYCRM_SYNC_VERSION', '1.0.0');
define('WC_KEYCRM_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_KEYCRM_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_KEYCRM_SYNC_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WC_KEYCRM_SYNC_MIN_WC_VERSION', '6.0');

/**
 * Check if WooCommerce is active and at the required version
 */
function wc_keycrm_sync_dependencies_met() {
    $active_plugins = (array) get_option('active_plugins', array());
    if (is_multisite()) {
        $active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
    }
    
    $woocommerce_is_active = in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins);
    
    if (!$woocommerce_is_active || !class_exists('WooCommerce')) {
        return false;
    }
    
    if (defined('WC_VERSION') && version_compare(WC_VERSION, WC_KEYCRM_SYNC_MIN_WC_VERSION, '<')) {
        return false;
    }
    
    return true;
}

/**
 * Display dependency error message
 */
function wc_keycrm_sync_dependency_notice() {
    $class = 'notice notice-error';
    if (!class_exists('WooCommerce')) {
        $message = sprintf(
            /* translators: %s: WooCommerce URL */
            __('KeyCRM Sync requires WooCommerce to be installed and activated. You can download %s here.', 'wc-keycrm-sync'),
            '<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a>'
        );
    } else {
        $message = sprintf(
            /* translators: %s: WooCommerce version */
            __('KeyCRM Sync requires WooCommerce version %s or higher.', 'wc-keycrm-sync'),
            WC_KEYCRM_SYNC_MIN_WC_VERSION
        );
    }
    printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), wp_kses_post($message));
}

/**
 * Main plugin class
 */
final class WC_KeyCRM_Sync {
    /** @var WC_KeyCRM_Sync Single instance */
    private static $instance = null;

    /** @var WC_KeyCRM_API API instance */
    private $api = null;

    /** @var WC_KeyCRM_Admin Admin instance */
    private $admin = null;

    /**
     * Main plugin instance.
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize plugin hooks
     */
    private function init_hooks() {
        // Check dependencies
        if (!wc_keycrm_sync_dependencies_met()) {
            add_action('admin_notices', 'wc_keycrm_sync_dependency_notice');
            return;
        }

        // Load plugin classes
        $this->includes();

        // Initialize components
        add_action('plugins_loaded', array($this, 'init_plugin'));
        add_action('woocommerce_checkout_order_processed', array($this, 'process_order'), 10, 1);
        add_action('woocommerce_order_status_changed', array($this, 'maybe_process_order'), 10, 3);

        // Filter to allow manual sync
        add_filter('wc_keycrm_sync_should_process_order', array($this, 'should_process_order'), 10, 2);
    }

    /**
     * Include required files
     */
    private function includes() {
        require_once WC_KEYCRM_SYNC_PLUGIN_DIR . 'includes/class-wc-keycrm-api.php';
        require_once WC_KEYCRM_SYNC_PLUGIN_DIR . 'includes/admin/class-wc-keycrm-admin.php';

        $this->api = new WC_KeyCRM_API();
        $this->admin = new WC_KeyCRM_Admin();
    }

    /**
     * Initialize plugin
     */
    public function init_plugin() {
        load_plugin_textdomain('wc-keycrm-sync', false, dirname(WC_KEYCRM_SYNC_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Process new order
     *
     * @param int $order_id Order ID
     */
    public function process_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Allow filtering whether to process the order
        if (!apply_filters('wc_keycrm_sync_should_process_order', true, $order)) {
            return;
        }

        $result = $this->api->send_order($order);
        
        if (is_wp_error($result)) {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: error message */
                    __('Failed to sync with KeyCRM: %s', 'wc-keycrm-sync'),
                    $result->get_error_message()
                )
            );
            return;
        }

        $order->add_order_note(__('Order synced with KeyCRM successfully', 'wc-keycrm-sync'));
        $order->update_meta_data('_keycrm_synced', 'yes');
        $order->save();
    }

    /**
     * Process order on status change if not already processed
     *
     * @param int    $order_id Order ID
     * @param string $old_status Old status
     * @param string $new_status New status
     */
    public function maybe_process_order($order_id, $old_status, $new_status) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_keycrm_synced') === 'yes') {
            return;
        }

        $this->process_order($order_id);
    }

    /**
     * Determine if order should be processed
     *
     * @param bool     $should_process Whether to process the order
     * @param WC_Order $order         Order object
     * @return bool
     */
    public function should_process_order($should_process, $order) {
        // Don't process if already synced
        if ($order->get_meta('_keycrm_synced') === 'yes') {
            return false;
        }

        // Don't process orders with specific statuses
        $excluded_statuses = apply_filters('wc_keycrm_sync_excluded_statuses', array(
            'cancelled',
            'failed',
            'refunded'
        ));

        if (in_array($order->get_status(), $excluded_statuses)) {
            return false;
        }

        return $should_process;
    }

    /** Prevent cloning */
    private function __clone() {}

    /** Prevent unserializing */
    public function __wakeup() {}
}

/**
 * Returns the main instance
 */
function WC_KeyCRM_Sync() {
    return WC_KeyCRM_Sync::instance();
}

// Initialize plugin on plugins loaded
add_action('plugins_loaded', 'WC_KeyCRM_Sync');