<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Blocks support for Paykka Payments (Embedded Card / Apple Pay / Google Pay).
 */
final class WC_Gateway_Paykka_Card_Support extends AbstractPaymentMethodType
{
    protected $name = 'paykka-card';

    public function initialize()
    {
        $settings       = get_option('woocommerce_paykka-card_settings', array());
        $this->settings = is_array($settings) ? $settings : array();
    }

    public function is_active()
    {
        if (($this->settings['enabled'] ?? 'no') !== 'yes') {
            return false;
        }
        $keys = function_exists('getPaykkaSettings') ? getPaykkaSettings() : array();
        return !empty($keys['paykka_client_key']) && !empty($keys['paykka_merchant_id']) && !empty($keys['paykka_private_key']);
    }

    public function get_payment_method_script_handles()
    {
        $version = '1.5.12';
        $checkout_url = function_exists('paykka_get_checkout_base_url') ? paykka_get_checkout_base_url() : '';
        $script_url   = rtrim($checkout_url, '/') . '/cp/card-checkout-ui.js';
        $style_url    = rtrim($checkout_url, '/') . '/cp/style.css';

        wp_enqueue_style('paykka-card-sdk', $style_url, array(), $version);
        wp_enqueue_style(
            'paykka-checkout-card-local',
            PAYKKA_PLUGIN_URL . 'assets/css/checkout-card.css',
            array('paykka-card-sdk'),
            $version
        );

        wp_register_script(
            'paykka-card-sdk',
            $script_url,
            array(),
            null,
            true
        );

        wp_register_script(
            'wc-paykka-card-gateway-blocks',
            PAYKKA_PLUGIN_URL . 'assets/js/blocks-card.js',
            // wp-data：脚本读取 wc/store/validation 拦截无效结账字段，必须显式依赖
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-html-entities', 'wp-data', 'paykka-card-sdk'),
            $version,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(
                'wc-paykka-card-gateway-blocks',
                'paykka-for-woocommerce',
                PAYKKA_PLUGIN_PATH . 'i18n/languages'
            );
        }

        return array('wc-paykka-card-gateway-blocks');
    }

    public function get_payment_method_data()
    {
        $settings = function_exists('getPaykkaSettings') ? getPaykkaSettings() : array();
        $env      = function_exists('paykka_is_sandbox') && paykka_is_sandbox()
            ? 'sandbox'
            : (get_option('paykka_api_region', 'eu') === 'hk' ? 'hk' : 'eu');
        $checkout_url = function_exists('paykka_get_checkout_base_url') ? paykka_get_checkout_base_url() : '';

        $title = isset($this->settings['title']) ? (string) $this->settings['title'] : '';
        if ($title === '' || $title === 'Credit Card') {
            $title = __('Payments', 'paykka-for-woocommerce');
        }

        return array(
            'title'       => $title,
            'description' => '',
            'supports'    => $this->get_supported_features(),
            'ajaxUrl'     => WC_AJAX::get_endpoint('paykka_card_create_session'),
            'noteAjaxUrl' => WC_AJAX::get_endpoint('paykka_card_place_order_note'),
            'nonce'       => wp_create_nonce('paykka_card_checkout'),
            'clientKey'   => isset($settings['paykka_client_key']) ? $settings['paykka_client_key'] : '',
            'env'         => $env,
            'gatewayId'   => 'paykka-card',
            'sdkUrl'      => rtrim($checkout_url, '/') . '/cp/card-checkout-ui.js',
            'checkoutBase'=> $checkout_url,
            'i18n'        => array(
                'loading'     => __('Preparing the secure payment form…', 'paykka-for-woocommerce'),
                'ready'       => __('Ready', 'paykka-for-woocommerce'),
                'error'       => __('The PayKKa payment form failed to load. Please refresh and try again.', 'paykka-for-woocommerce'),
                'needBilling' => __('Please enter your billing email and country/region first.', 'paykka-for-woocommerce'),
                'notReady'    => __('The payment form is not ready yet. Please wait a moment before placing your order.', 'paykka-for-woocommerce'),
                'paying'      => __('Processing payment…', 'paykka-for-woocommerce'),
                'needCard'    => __('Please enter your complete card details before placing the order, or use Apple Pay / Google Pay.', 'paykka-for-woocommerce'),
                'fixInvalid' => __('Please fix the billing and address errors on the checkout page before paying.', 'paykka-for-woocommerce'),
            ),
        );
    }
}
