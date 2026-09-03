<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Blocks support for Paykka Hosted.
 */
final class WC_Gateway_Paykka_Support extends AbstractPaymentMethodType
{
    protected $name = 'paykka';

    public function initialize()
    {
        $this->settings = array(
            'enabled'     => get_option('paykka_enabled', 'yes'),
            'title'       => get_option('paykka_title', __('Paykka Hosted', 'paykka-for-woocommerce')),
            'description' => get_option('paykka_description', __('使用 Paykka Hosted 收银台安全支付', 'paykka-for-woocommerce')),
        );
    }

    public function is_active()
    {
        return get_option('paykka_enabled', 'yes') === 'yes';
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'wc-paykka-gateway-blocks',
            PAYKKA_PLUGIN_URL . 'assets/js/blocks.js',
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'),
            '1.5.11',
            true
        );
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(
                'wc-paykka-gateway-blocks',
                'paykka-for-woocommerce',
                PAYKKA_PLUGIN_PATH . 'i18n/languages'
            );
        }
        return array('wc-paykka-gateway-blocks');
    }

    public function get_payment_method_data()
    {
        return array(
            'title'       => $this->settings['title'],
            'description' => $this->settings['description'],
            'supports'    => array('products'),
            'paykka_mode' => 'hosted',
        );
    }
}
