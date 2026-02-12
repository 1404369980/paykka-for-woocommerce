<?php
namespace lib\Paykka\Request;

class PaykkaCallBackHandler
{
    // private $gateway;
    /**
     * 初始化 Webhook 处理类
     */
    public function __construct()
    {
   
        // 注册 Webhook 端点
        add_action('woocommerce_api_wc_gateway_paykka_payment_callback', array($this, 'handle_payment_callback'));
    }

    public static function getCallbackUrl($order_id)
    {
        return add_query_arg(array(
            'wc-api'   => 'WC_Gateway_Paykka_Payment_callback',
            'order_id' => $order_id,
        ), home_url('/'));
    }

    public function handle_payment_callback()
    {
        $order_id = isset($_REQUEST['order_id']) ? absint($_REQUEST['order_id']) : 0;
        if (!$order_id) {
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }

        $order = wc_get_order($order_id);
        if (!$order || !$order->get_id()) {
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }

        if ($order->get_status() === 'processing' || $order->get_status() === 'completed') {
            wp_safe_redirect(wc_get_endpoint_url('view-order', $order_id, wc_get_page_permalink('myaccount')));
            exit;
        }

        $order->payment_complete();
        wc_reduce_stock_levels($order_id);

        wp_safe_redirect(wc_get_endpoint_url('view-order', $order_id, wc_get_page_permalink('myaccount')));
        exit;
    }
}