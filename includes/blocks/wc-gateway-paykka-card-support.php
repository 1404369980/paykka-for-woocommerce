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
        $version = '1.5.4';
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
            plugin_url_paykka() . '/assets/js/blocks-card.js',
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-html-entities', 'paykka-card-sdk'),
            $version,
            true
        );

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
            'nonce'       => wp_create_nonce('paykka_card_checkout'),
            'clientKey'   => isset($settings['paykka_client_key']) ? $settings['paykka_client_key'] : '',
            'env'         => $env,
            'gatewayId'   => 'paykka-card',
            'sdkUrl'      => rtrim($checkout_url, '/') . '/cp/card-checkout-ui.js',
            'checkoutBase'=> $checkout_url,
            'i18n'        => array(
                'loading'     => __('正在准备安全支付表单…', 'paykka-for-woocommerce'),
                'ready'       => __('Ready', 'paykka-for-woocommerce'),
                'error'       => __('PayKKa 支付表单加载失败，请刷新后重试。', 'paykka-for-woocommerce'),
                'needBilling' => __('请先填写账单邮箱和国家/地区。', 'paykka-for-woocommerce'),
                'notReady'    => __('支付表单尚未就绪，请稍候再下单。', 'paykka-for-woocommerce'),
                'paying'      => __('正在处理支付…', 'paykka-for-woocommerce'),
                'needCard'    => __('请填写完整的银行卡信息后再下单，或使用 Apple Pay / Google Pay。', 'paykka-for-woocommerce'),
                'fixInvalid' => __('请先修正结账页账单/地址等错误后再支付。', 'paykka-for-woocommerce'),
            ),
        );
    }
}
