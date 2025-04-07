<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
/**
 * WC_Gateway_Paykka_Support
 */
final class WC_Gateway_Paykka_Drop_In_Support extends AbstractPaymentMethodType
{

    /**
     * 支付网关的 ID
     *
     * @var string
     */
    protected $name = 'paykka-drop-in'; // 替换为你的支付网关 ID

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

    // public function get_payment_method_script_handles()
    // {
    //     $script_path = '/assets/js/blocks-drop-in.js';
    //     $script_url = plugin_url_paykka() . $script_path;
    //     wp_register_script(
    //         'wc-paykka-drop-in-gateway-blocks', // 脚本句柄
    //         $script_url, // 脚本路径
    //         array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'), // 依赖
    //         '1.0.0', // 版本号
    //         true // 是否在页脚加载
    //     );
    //     return array('wc-paykka-drop-in-gateway-blocks');
    // }

    /**
     * 注册支付方法的脚本
     */
    public function get_payment_method_script_handles()
    {
        $script_path = '/assets/js/blocks-drop-in.js';
        $script_url = plugin_url_paykka() . $script_path;

        wp_register_script(
            'paykka-sdk-js',
            'https://checkout-sandbox.aq.paykka.com/cp/encrypted-card.js',
            [],
            null,
            [
                'strategy' => 'async'
            ]
        );
        wp_enqueue_script('paykka-sdk-js');


        wp_register_script(
            'wc-paykka-drop-in-gateway-blocks', // 脚本句柄
            $script_url, // 脚本路径
            array(
                'paykka-sdk-js',
                // 'react',
                // 'react-dom',
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                // 'wp-i18n',
                // 'wp-polyfill',
                'wp-element',
                // 'wp-plugins',
                'wp-data',
                // 'wc-checkout',
                'wp-api'
            ), // 依赖
            '1.0.0', // 版本号
            true // 是否在页脚加载
        );

        // 本地化脚本，传递必要的设置
        // wp_localize_script('paykka-checkout', 'paykkaSettings', array(
        //     'root' => esc_url_raw(rest_url()),
        //     'nonce' => wp_create_nonce('wp_rest'),
        //     'current_user_id' => get_current_user_id()
        // ));

        // 添加 `type="module"`
        // add_filter('script_loader_tag', function ($tag, $handle) {
        //     if ($handle === 'wc-paykka-drop-in-gateway-blocks') {
        //         return str_replace('<script ', '<script type="module" ', $tag);
        //     }
        //     return $tag;
        // }, 10, 2);

        // wp_add_inline_script(
        //     'wc-paykka-drop-in-gateway-blocks',
        //     'wp.blocks.registerPaymentMethod( window.paykkaPaymentGateway );',
        //     'after'
        // );

        return array('wc-paykka-drop-in-gateway-blocks');
    }
    /**
     * 获取支付方法的数据
     *
     * @return array
     */
    public function get_payment_method_data()
    {
        return array(
            'title' => $this->settings['title'] ?? __('PayKKa Drop In Gateway', 'paykka-for-woocommerce'),
            'description' => $this->settings['description'] ?? __('Pay Drop In Gateway', 'paykka-for-woocommerce'),
            'supports' => array('products'), // 支持的支付功能
            'content' => null, // 必须明确返回null或React元素
        );
    }
}