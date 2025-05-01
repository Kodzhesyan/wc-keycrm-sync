<?php
/**
 * KeyCRM API Integration
 *
 * @package WC_KeyCRM_Sync
 */

defined('ABSPATH') || exit;

class WC_KeyCRM_API {
    /**
     * API endpoint
     *
     * @var string
     */
    private $api_url = 'https://openapi.keycrm.app/v1';

    /**
     * API key
     *
     * @var string
     */
    private $api_key;

    /**
     * Source ID for orders
     *
     * @var int
     */
    private $source_id;

    /**
     * Debug mode
     *
     * @var bool
     */
    private $debug_mode;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->api_key = get_option('wc_keycrm_api_key');
        $this->source_id = (int)get_option('wc_keycrm_source_id', 1);
        $this->debug_mode = (bool)get_option('wc_keycrm_debug_mode', false);
    }

    /**
     * Send order to KeyCRM
     *
     * @param WC_Order $order Order object
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function send_order($order) {
        if (!$this->api_key) {
            return new WP_Error('missing_api_key', __('API ключ KeyCRM не встановлено', 'wc-keycrm-sync'));
        }

        try {
            $data = $this->prepare_order_data($order);
            return $this->send_request('order', $data);
        } catch (Exception $e) {
            $this->log_error($e->getMessage());
            return new WP_Error('api_error', $e->getMessage());
        }
    }

    /**
     * Prepare order data according to KeyCRM API requirements
     *
     * @param WC_Order $order Order object
     * @return array
     */
    private function prepare_order_data($order) {
        // Get shipping method instance ID
        $shipping_methods = $order->get_shipping_methods();
        $shipping_method = reset($shipping_methods);
        $instance_id = $shipping_method ? $shipping_method->get_instance_id() : null;
        
        // Get mapped delivery service ID or fallback to default (1)
        $shipping_mappings = get_option('wc_keycrm_shipping_mappings', array());
        $delivery_service_id = ($instance_id && isset($shipping_mappings[$instance_id])) 
            ? $shipping_mappings[$instance_id] 
            : 1;

        // Формируем имя получателя в зависимости от службы доставки
        $recipient_full_name = $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name();
        if ($delivery_service_id === 2) {
            $recipient_full_name .= ' .';
        }

        // Get payment method mapping
        $payment_method = $order->get_payment_method();
        $payment_mappings = get_option('wc_keycrm_payment_mappings', array());
        $payment_method_id = isset($payment_mappings[$payment_method]) ? $payment_mappings[$payment_method] : 2;

        // Prepare payment data
        $payment_data = array(
            'payment_method_id' => $payment_method_id,
            'payment_method' => $order->get_payment_method() === 'cod' ? 'Оплата при отриманні' : $order->get_payment_method_title(),
            'amount' => (float) $order->get_total(),
            // 'description' => 'Авансовий платіж',
            'payment_date' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'status' => $order->is_paid() ? 'paid' : 'not_paid'
        );

        $data = [
            'source_id' => $this->source_id,
            'external_id' => $order->get_id(),
            'buyer' => [
                'full_name' => $order->get_formatted_billing_full_name(),
                'email' => $order->get_billing_email(),
                'phone' => $this->format_phone($order->get_billing_phone())
            ],
            'shipping' => [
                'delivery_service_id' => $delivery_service_id,
                'tracking_code' => $order->get_meta('_tracking_code', true),
                'shipping_service' => $order->get_shipping_method(),
                'shipping_address_city' => $order->get_billing_city(),
                'shipping_address_country' => $order->get_billing_country(),
                'shipping_address_region' => $order->get_billing_state(),
                'shipping_address_zip' => $order->get_billing_postcode(),
                'shipping_secondary_line' => $order->get_billing_address_1(),
                'shipping_receive_point' => $order->get_shipping_address_1(),
                'recipient_full_name' => $recipient_full_name,
                'recipient_phone' => $this->format_phone($order->get_billing_phone()),
                'warehouse_ref' => $order->get_meta('wcus_warehouse_ref', true),
                'shipping_date' => $order->get_date_created()->format('Y-m-d')
            ],
            'payments' => [$payment_data],
            'products' => $this->get_order_items($order)
        ];

        return $data;
    }

    /**
     * Format phone number to international format
     *
     * @param string $phone Phone number
     * @return string
     */
    private function format_phone($phone) {
        // Remove everything except digits
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Add + if it's missing
        if (substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        }
        
        return $phone;
    }

    /**
     * Get order items data
     *
     * @param WC_Order $order Order object
     * @return array
     */
    private function get_order_items($order) {
        $items = [];
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            // Get product image URL
            $image_id = $product->get_image_id();
            $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'full') : '';

            $items[] = [
                'sku' => $product->get_sku(),
                'name' => $item->get_name(),
                'price' => $item->get_total() / $item->get_quantity(),
                'purchased_price' => $product->get_meta('_purchase_price', true),
                'quantity' => $item->get_quantity(),
                'image_url' => $image_url
            ];
        }

        return $items;
    }

    /**
     * Send request to KeyCRM API
     *
     * @param string $endpoint API endpoint
     * @param array  $data     Request data
     * @return bool|WP_Error
     */
    private function send_request($endpoint, $data) {
                $url = trailingslashit($this->api_url) . $endpoint;
        
        $this->log_debug('Sending request to: ' . $url);
        $this->log_debug('Request data: ' . wp_json_encode($data));

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache'
            ],
            'body' => wp_json_encode($data),
            'timeout' => 30,
            'data_format' => 'body'
        ]);

        if (is_wp_error($response)) {
            $this->log_error('Request failed: ' . $response->get_error_message());
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->log_debug('Response code: ' . $response_code);
        $this->log_debug('Response body: ' . $body);

        if ($response_code !== 200 && $response_code !== 201) {
            $error_message = sprintf(
                __('KeyCRM API error (code %d): %s', 'wc-keycrm-sync'),
                $response_code,
                $body
            );
            return new WP_Error('api_error', $error_message);
        }

        return true;
    }

    /**
     * Log debug message
     *
     * @param string $message Debug message
     */
    private function log_debug($message) {
        if ($this->debug_mode) {
            error_log('KeyCRM Налагодження: ' . $message);
        }
    }

    /**
     * Log error message
     *
     * @param string $message Error message
     */
    private function log_error($message) {
        error_log('KeyCRM Помилка: ' . $message);
    }
}