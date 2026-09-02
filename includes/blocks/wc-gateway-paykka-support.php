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
        $script_url = plugin_url_paykka() . '/assets/js/blocks.js';
        wp_register_script(
            'wc-paykka-gateway-blocks',
            $script_url,
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'),
            '1.4.1',
            true
        );
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
