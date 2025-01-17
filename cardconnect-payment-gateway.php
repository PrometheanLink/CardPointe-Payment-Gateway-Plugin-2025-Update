<?php
/**
 * Plugin Name: CardPointe Payment Gateway for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/cardconnect-payment-module
 * Description: Secure credit card payments for WooCommerce.
 * Version: 4.0.0
 * Author: Fiserv <nicole.anderson@fiserv.com> | 2025 PrometheanLink LLC Update - <service@prometheanlink.com>
 * Author URI: https://cardconnect.com
 * License: GNU General Public License v2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 5.0
 * WC tested up to: 7.0.0
 * @version 4.0.0
 * @author  CardPointe
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

define('WC_CARDCONNECT_VER', '4.0.0');
define('WC_CARDCONNECT_PLUGIN_PATH', untrailingslashit(plugin_basename(__DIR__)));
define('WC_CARDCONNECT_PLUGIN_URL', untrailingslashit(plugins_url('', __FILE__)));
define('CARDCONNECT_API_URL', 'https://<site>.cardpointe.com/api/v3');

define('CARDCONNECT_ALLOWED_IPS', [
    '198.62.138.0/24',
    '206.201.63.0/24'
]);

define('CARDCONNECT_RECAPTCHA_SITE_KEY', get_option('cardconnect_recaptcha_site_key'));
define('CARDCONNECT_RECAPTCHA_SECRET_KEY', get_option('cardconnect_recaptcha_secret_key'));

// Ensure secure API key storage
function get_cardconnect_api_key() {
    return get_option('cardconnect_api_key');
}

add_action('plugins_loaded', 'CardConnectPaymentGateway_init', 0);

function CardConnectPaymentGateway_init() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    include_once 'classes/class-wc-gateway-cardconnect.php';
    include_once 'classes/class-wc-gateway-cardconnect-saved-cards.php';
    
    if (class_exists('WC_Subscriptions_Order')) {
        include_once 'classes/class-wc-gateway-cardconnect-addons.php';
    }

    add_filter('woocommerce_payment_gateways', function ($methods) {
        $methods[] = 'CardConnectPaymentGateway';
        return $methods;
    });
}

// Verify reCAPTCHA
function cardconnect_verify_recaptcha($token) {
    $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
        'body' => [
            'secret' => CARDCONNECT_RECAPTCHA_SECRET_KEY,
            'response' => $token
        ]
    ]);
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $result = json_decode(wp_remote_retrieve_body($response), true);
    return !empty($result['success']);
}

// Secure API Request
function cardconnect_api_request($endpoint, $body = [], $method = 'POST') {
    $api_key = get_cardconnect_api_key();
    if (!$api_key) {
        return new WP_Error('api_key_missing', 'CardConnect API key is missing');
    }
    
    $args = [
        'method' => $method,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($api_key),
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($body),
        'timeout' => 45
    ];

    $response = wp_remote_request(CARDCONNECT_API_URL . $endpoint, $args);

    if (is_wp_error($response)) {
        return $response;
    }

    return json_decode(wp_remote_retrieve_body($response), true);
}

// Validate Session Key
function cardconnect_validate_session() {
    $session_key = get_transient('cardconnect_session_key');
    if (!$session_key) {
        $response = cardconnect_api_request('/connect', ['force' => 'true']);
        if (!empty($response['x-cardconnect-sessionkey'])) {
            set_transient('cardconnect_session_key', $response['x-cardconnect-sessionkey'], 600);
            return $response['x-cardconnect-sessionkey'];
        }
        return false;
    }
    return $session_key;
}

// Process Payment with reCAPTCHA
function cardconnect_process_payment($order_id, $recaptcha_token) {
    if (!cardconnect_verify_recaptcha($recaptcha_token)) {
        return new WP_Error('recaptcha_failed', 'reCAPTCHA verification failed.');
    }
    
    $order = wc_get_order($order_id);
    $session_key = cardconnect_validate_session();
    if (!$session_key) {
        return new WP_Error('session_error', 'Session key validation failed');
    }
    
    $payment_data = [
        'amount' => $order->get_total(),
        'currency' => get_woocommerce_currency(),
        'orderId' => (string) $order_id,
        'token' => get_post_meta($order_id, '_cardconnect_token', true),
        'sessionKey' => $session_key
    ];
    
    $response = cardconnect_api_request('/authCard', $payment_data);
    
    if (!empty($response['respstat']) && $response['respstat'] === 'A') {
        $order->payment_complete($response['retref']);
        return ['result' => 'success', 'redirect' => $order->get_checkout_order_received_url()];
    } else {
        return new WP_Error('payment_error', $response['resptext'] ?? 'Payment failed');
    }
}

add_action('woocommerce_api_cardconnect_process_payment', 'cardconnect_process_payment', 10, 2);

// Refund Transactions
function cardconnect_refund_transaction($order_id) {
    $order = wc_get_order($order_id);
    $transaction_id = $order->get_transaction_id();
    if (!$transaction_id) {
        return new WP_Error('refund_error', 'No transaction ID found');
    }
    
    $response = cardconnect_api_request('/voidOrRefund', [
        'retref' => $transaction_id,
        'amount' => $order->get_total()
    ]);
    
    if (!empty($response['respstat']) && $response['respstat'] === 'A') {
        $order->update_status('refunded', 'Transaction successfully refunded.');
        return true;
    }
    return new WP_Error('refund_error', 'Refund failed: ' . ($response['resptext'] ?? 'Unknown error'));
}

add_action('woocommerce_order_status_refunded', 'cardconnect_refund_transaction');
?>
