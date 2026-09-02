<?php
namespace lib\Paykka\Request;

class PaykkaCallBackHandler
{
    public function __construct()
    {
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
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $order = wc_get_order($order_id);
        if (!$order || !$order->get_id()) {
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        if ($order->get_status() === 'processing' || $order->get_status() === 'completed') {
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }
            if (function_exists('WC') && WC()->session) {
                WC()->session->__unset('paykka_card_checkout_order_id');
                WC()->session->__unset('paykka_checkout_order_id');
            }
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        $paykkaPaymentHelper = new PaykkaRequestHandler();
        $query_result = $paykkaPaymentHelper->queryPaymentForOrder($order);

        if (is_array($query_result) && isset($query_result['ret_code']) && $query_result['ret_code'] === '000000') {
            $paykkaPaymentHelper->syncOrderByQueryResult($order, $query_result, 'callback');
            $order = wc_get_order($order_id);

            $status = '';
            if (isset($query_result['status'])) {
                $status = strtoupper((string) $query_result['status']);
            } elseif (isset($query_result['data']['status'])) {
                $status = strtoupper((string) $query_result['data']['status']);
            }

            if (in_array($status, array('SUCCESS', 'AUTHORIZED'), true)
                || ($order && in_array($order->get_status(), array('processing', 'completed', 'on-hold'), true))
            ) {
                if (function_exists('WC') && WC()->cart) {
                    WC()->cart->empty_cart();
                }
                if (function_exists('WC') && WC()->session) {
                    WC()->session->__unset('paykka_card_checkout_order_id');
                    WC()->session->__unset('paykka_checkout_order_id');
                }
                wp_safe_redirect($order->get_checkout_order_received_url());
                exit;
            }

            // 查到了但未成功（FAILURE / PROCESSING 等）：回结账页，不进感谢页
            if (function_exists('wc_add_notice')) {
                if ($status === 'FAILURE' || $status === 'CANCELED') {
                    wc_add_notice(__('支付未成功，请重试或更换支付方式。', 'paykka-for-woocommerce'), 'error');
                } else {
                    wc_add_notice(__('支付结果确认中，请稍候刷新或联系客服。', 'paykka-for-woocommerce'), 'notice');
                }
            }
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        if (function_exists('paykka_is_debug') && paykka_is_debug()) {
            error_log('[Paykka Callback] Query failed order_id=' . $order_id . ' result=' . wp_json_encode($query_result));
        }
        $order->add_order_note('PayKKa callback query failed, keep current status.');
        $order->save();
        if (function_exists('wc_add_notice')) {
            wc_add_notice(__('暂时无法确认支付结果，请稍后再试或联系客服。', 'paykka-for-woocommerce'), 'error');
        }
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }
}
