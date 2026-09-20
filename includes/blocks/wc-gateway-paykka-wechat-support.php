<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Blocks support for Paykka WeChat Pay (popup Hosted session).
 */
final class WC_Gateway_Paykka_Wechat_Support extends AbstractPaymentMethodType
{
    protected $name = 'paykka-wechat';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_paykka-wechat_settings', array());
        if (!is_array($this->settings)) {
            $this->settings = array();
        }
    }

    public function is_active()
    {
        return isset($this->settings['enabled']) && $this->settings['enabled'] === 'yes';
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'wc-paykka-wechat-gateway-blocks',
            PAYKKA_PLUGIN_URL . 'assets/js/blocks-wechat.js',
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-html-entities'),
            '1.5.18',
            true
        );
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(
                'wc-paykka-wechat-gateway-blocks',
                'paykka-for-woocommerce',
                PAYKKA_PLUGIN_PATH . 'i18n/languages'
            );
        }
        return array('wc-paykka-wechat-gateway-blocks');
    }

    public function get_payment_method_data()
    {
        return array(
            'title'       => isset($this->settings['title'])
                ? $this->settings['title']
                : __('WeChat Pay', 'paykka-for-woocommerce'),
            'description' => isset($this->settings['description'])
                ? $this->settings['description']
                : __('Pay with WeChat Pay in a secure popup window.', 'paykka-for-woocommerce'),
            'supports'    => array('products'),
            'paykka_mode' => 'wechat',
        );
    }
}
