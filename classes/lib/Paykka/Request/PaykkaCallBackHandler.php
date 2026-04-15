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

        require_once PAYKKA_PATH_FILE_PAYKKA_REQUEST_HANDLER;
        $paykkaPaymentHelper = new PaykkaRequestHandler();
        $query_result = $paykkaPaymentHelper->queryPayment((string) $order_id, '', '');
        if (is_array($query_result) && isset($query_result['ret_code']) && $query_result['ret_code'] === '000000') {
            $paykkaPaymentHelper->syncOrderByQueryResult($order, $query_result, 'callback');
        } else {
            if (function_exists('paykka_is_debug') && paykka_is_debug()) {
                error_log('[Paykka Callback] Query failed order_id=' . $order_id . ' result=' . wp_json_encode($query_result));
            }
            $order->add_order_note('PayKKa callback query failed, keep current status.');
            $order->save();
        }

        wp_safe_redirect(wc_get_endpoint_url('view-order', $order_id, wc_get_page_permalink('myaccount')));
        exit;
    }
}
