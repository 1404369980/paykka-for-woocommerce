<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
/**
 * WC_Gateway_Paykka_Support
 */
final class WC_Gateway_Paykka_Embedded_Support extends AbstractPaymentMethodType
{

    /**
     * 支付网关的 ID
     *
     * @var string
     */
    protected $name = 'paykka-embedded'; // 替换为你的支付网关 ID

    /**
     * 初始化支付方法
     */
    public function initialize()
    {
        $this->settings = get_option('woocommerce_paykka_gateway_settings', array()); // 替换为你的支付网关设置选项
    }

    /**
     * 检查支付方法是否可用
     *
     * @return bool
     */
    public function is_active()
    {
        // error_log("is_active:". ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled']);
        return true;
    }

    /**
     * 注册支付方法的脚本
     */
    public function get_payment_method_script_handles()
    {
        $script_path = '/assets/js/blocks-embedded.js';
        $script_url = plugin_url_paykka() . $script_path;

        wp_register_script(
            'wc-paykka-embedded-gateway-blocks', // 脚本句柄
            $script_url, // 脚本路径
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'), // 依赖
            '1.0.0', // 版本号
            true // 是否在页脚加载
        );
        return array('wc-paykka-embedded-gateway-blocks');
    }

    /**
     * 获取支付方法的数据
     *
     * @return array
     */
    public function get_payment_method_data()
    {
        return array(
            'title' => $this->settings['title'] ?? __('PayKKa Embedded Gateway', 'paykka-for-woocommerce'),
            'description' => $this->settings['description'] ?? __('Pay Embedded Checkout Gateway', 'paykka-for-woocommerce'),
            'supports' => array('products'), // 支持的支付功能
        );
    }
}