<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
/**
 * WC_Gateway_Paykka_Support
 */
final class WC_Gateway_Paykka_Support extends AbstractPaymentMethodType
{

    /**
     * 支付网关的 ID
     *
     * @var string
     */
    protected $name = 'paykka'; // 替换为你的支付网关 ID

    /**
     * 初始化支付方法
     */
    public function initialize()
    {
        $this->settings = array(
            'enabled' => get_option('paykka_enabled', 'yes'),
            'title'   => get_option('paykka_title', __('Paykka', 'paykka-for-woocommerce')),
            'description' => get_option('paykka_description', __('使用 Paykka 安全支付', 'paykka-for-woocommerce')),
        );
    }

    /**
     * 检查支付方法是否可用（与 PayPal 一致：主开关开启即可）
     *
     * @return bool
     */
    public function is_active()
    {
        return get_option('paykka_enabled', 'yes') === 'yes';
    }

    /**
     * 注册支付方法的脚本
     */
    public function get_payment_method_script_handles()
    {
        $script_path = '/assets/js/blocks.js';
        $script_url = plugin_url_paykka() . $script_path;

        wp_register_script(
            'wc-paykka-gateway-blocks', // 脚本句柄
            $script_url, // 脚本路径
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'), // 依赖
            '1.0.0', // 版本号
            true // 是否在页脚加载
        );
        return array('wc-paykka-gateway-blocks');
    }

    /**
     * 获取支付方法的数据
     *
     * @return array
     */
    public function get_payment_method_data()
    {
        return array(
            'title'       => $this->settings['title'],
            'description' => $this->settings['description'],
            'supports'    => array('products'),
            'paykka_mode' => get_option('paykka_payment_mode', 'hosted'),
        );
    }
}