<?php
/**
 * KeyCRM Admin Settings
 *
 * @package WC_KeyCRM_Sync
 */

defined('ABSPATH') || exit;

class WC_KeyCRM_Admin {
    /**
     * Constructor.
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Add menu item.
     */
    public function add_admin_menu() {
        add_menu_page(
            __('KeyCRM Settings', 'wc-keycrm-sync'),
            __('KeyCRM Sync', 'wc-keycrm-sync'),
            'manage_options',
            'wc-keycrm-settings',
            array($this, 'settings_page'),
            'dashicons-update'
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // API key setting
        register_setting(
            'wc_keycrm_settings',
            'wc_keycrm_api_key',
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => ''
            )
        );
        
        // Source ID setting
        register_setting(
            'wc_keycrm_settings',
            'wc_keycrm_source_id',
            array(
                'type' => 'integer',
                'sanitize_callback' => 'absint',
                'default' => 1
            )
        );
        
        // Debug mode setting
        register_setting(
            'wc_keycrm_settings',
            'wc_keycrm_debug_mode',
            array(
                'type' => 'boolean',
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default' => false
            )
        );

        // Payment mappings setting
        register_setting(
            'wc_keycrm_settings',
            'wc_keycrm_payment_mappings',
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_payment_mappings'),
                'default' => array()
            )
        );

        // Shipping mappings setting
        register_setting(
            'wc_keycrm_settings',
            'wc_keycrm_shipping_mappings',
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_shipping_mappings'),
                'default' => array()
            )
        );
    }

    public function sanitize_shipping_mappings($input) {
        $sanitized = array();
        
        if (is_array($input)) {
            foreach ($input as $method_id => $service_id) {
                $sanitized[$method_id] = absint($service_id);
            }
        }
        
        return $sanitized;
    }

    public function sanitize_payment_mappings($input) {
        $sanitized = array();
        
        if (is_array($input)) {
            foreach ($input as $method_id => $payment_id) {
                $sanitized[$method_id] = absint($payment_id);
            }
        }
        
        return $sanitized;
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_wc-keycrm-settings' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'wc-keycrm-admin',
            WC_KEYCRM_SYNC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WC_KEYCRM_SYNC_VERSION
        );
    }

    /**
     * Settings page.
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'wc_keycrm_messages',
                'wc_keycrm_message',
                __('Settings Saved', 'wc-keycrm-sync'),
                'updated'
            );
        }

        // Get WooCommerce payment gateways
        $payment_gateways = WC()->payment_gateways->payment_gateways();
        $saved_payment_mappings = get_option('wc_keycrm_payment_mappings', array());

        // Получаем все методы доставки WooCommerce
        $shipping_methods = array();
        $zones = WC_Shipping_Zones::get_zones();
        
        // Добавляем методы из зон доставки
        foreach ($zones as $zone_data) {
            $zone = new WC_Shipping_Zone($zone_data['id']);
            $methods = $zone->get_shipping_methods();
            foreach ($methods as $method) {
                $method_id = $method->get_instance_id();
                $shipping_methods[$method_id] = array(
                    'title' => $zone_data['zone_name'] . ' - ' . $method->get_title(),
                    'id' => $method->id,
                    'instance_id' => $method_id
                );
            }
        }
        
        // Добавляем глобальные методы доставки
        $global_zone = new WC_Shipping_Zone(0);
        $global_methods = $global_zone->get_shipping_methods();
        foreach ($global_methods as $method) {
            $method_id = $method->get_instance_id();
            $shipping_methods[$method_id] = array(
                'title' => __('Rest of the World', 'wc-keycrm-sync') . ' - ' . $method->get_title(),
                'id' => $method->id,
                'instance_id' => $method_id
            );
        }

        $saved_mappings = get_option('wc_keycrm_shipping_mappings', array());
        ?>
        <div class="wrap wc-keycrm-settings-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php settings_errors('wc_keycrm_messages'); ?>

            <form action="options.php" method="post">
                <?php
                settings_fields('wc_keycrm_settings');
                do_settings_sections('wc_keycrm_settings');
                ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="wc_keycrm_api_key"><?php esc_html_e('API Key', 'wc-keycrm-sync'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="wc_keycrm_api_key"
                                   name="wc_keycrm_api_key" 
                                   value="<?php echo esc_attr(get_option('wc_keycrm_api_key')); ?>" 
                                   class="regular-text"
                                   required>
                            <p class="description">
                                <?php esc_html_e('Enter your KeyCRM API key. You can find it in your KeyCRM account settings.', 'wc-keycrm-sync'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wc_keycrm_source_id"><?php esc_html_e('Source ID', 'wc-keycrm-sync'); ?></label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="wc_keycrm_source_id"
                                   name="wc_keycrm_source_id" 
                                   value="<?php echo esc_attr(get_option('wc_keycrm_source_id', 1)); ?>" 
                                   class="small-text"
                                   min="1"
                                   required>
                            <p class="description">
                                <?php esc_html_e('Enter your KeyCRM source ID for orders. This helps track where orders come from.', 'wc-keycrm-sync'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Debug Mode', 'wc-keycrm-sync'); ?>
                        </th>
                        <td>
                            <fieldset>
                                <label for="wc_keycrm_debug_mode">
                                    <input type="checkbox" 
                                           id="wc_keycrm_debug_mode"
                                           name="wc_keycrm_debug_mode" 
                                           value="1" 
                                           <?php checked(1, get_option('wc_keycrm_debug_mode', 0)); ?>>
                                    <?php esc_html_e('Enable debug logging', 'wc-keycrm-sync'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When enabled, debug information will be logged to the WordPress debug log.', 'wc-keycrm-sync'); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Payment Methods Mapping', 'wc-keycrm-sync'); ?>
                        </th>
                        <td>
                            <table class="shipping-mapping-table">
                                <thead>
                                    <tr>
                                        <th><?php _e('Payment Method', 'wc-keycrm-sync'); ?></th>
                                        <th><?php _e('KeyCRM Payment Method ID', 'wc-keycrm-sync'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payment_gateways as $gateway) : ?>
                                        <?php if ($gateway->enabled !== 'yes') continue; ?>
                                        <tr>
                                            <td>
                                                <div class="method-title">
                                                    <?php echo esc_html($gateway->get_title()); ?>
                                                </div>
                                                <p class="method-info">
                                                    <?php 
                                                    printf(
                                                        __('Gateway ID: %s', 'wc-keycrm-sync'),
                                                        $gateway->id
                                                    ); 
                                                    ?>
                                                </p>
                                            </td>
                                            <td>
                                                <input type="number" 
                                                       name="wc_keycrm_payment_mappings[<?php echo esc_attr($gateway->id); ?>]" 
                                                       value="<?php echo esc_attr(isset($saved_payment_mappings[$gateway->id]) ? $saved_payment_mappings[$gateway->id] : '2'); ?>"
                                                       min="1"
                                                       class="small-text">
                                                <?php if ($gateway->id === 'cod') : ?>
                                                    <p class="recommended">
                                                        <?php _e('Recommended ID: 1 (Cash on Delivery)', 'wc-keycrm-sync'); ?>
                                                    </p>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p class="description">
                                <?php _e('Map WooCommerce payment methods to KeyCRM payment method IDs.', 'wc-keycrm-sync'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">
                            <?php _e('Shipping Methods Mapping', 'wc-keycrm-sync'); ?>
                        </th>
                        <td>
                            <table class="shipping-mapping-table">
                                <thead>
                                    <tr>
                                        <th><?php _e('Shipping Zone and Method', 'wc-keycrm-sync'); ?></th>
                                        <th><?php _e('KeyCRM Delivery Service ID', 'wc-keycrm-sync'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($shipping_methods as $instance_id => $method) : ?>
                                        <tr>
                                            <td>
                                                <div class="method-title">
                                                    <?php echo esc_html($method['title']); ?>
                                                </div>
                                                <p class="method-info">
                                                    <?php 
                                                    printf(
                                                        __('Method: %s (Instance ID: %s)', 'wc-keycrm-sync'),
                                                        $method['id'],
                                                        $instance_id
                                                    ); 
                                                    ?>
                                                </p>
                                            </td>
                                            <td>
                                                <input type="number" 
                                                       name="wc_keycrm_shipping_mappings[<?php echo esc_attr($instance_id); ?>]" 
                                                       value="<?php echo esc_attr(isset($saved_mappings[$instance_id]) ? $saved_mappings[$instance_id] : '1'); ?>"
                                                       min="1"
                                                       class="small-text">
                                                <?php if ($method['id'] === 'nova_poshta_shipping') : ?>
                                                    <p class="recommended">
                                                        <?php _e('Recommended ID: 1 (Nova Poshta)', 'wc-keycrm-sync'); ?>
                                                    </p>
                                                <?php elseif ($method['id'] === 'ukrposhta_shipping') : ?>
                                                    <p class="recommended">
                                                        <?php _e('Recommended ID: 2 (Ukrposhta)', 'wc-keycrm-sync'); ?>
                                                    </p>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p class="description">
                                <?php _e('Map WooCommerce shipping methods to KeyCRM delivery service IDs. Each shipping method instance can have its own mapping.', 'wc-keycrm-sync'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
?>