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

    /**
     * 「为已有订单付款」（order-pay）发起的支付：购物车与本单无关，失败也应回到该订单的付款页。
     *
     * @param \WC_Order $order
     * @return bool
     */
    private static function isPayForOrder($order)
    {
        return $order && $order->get_meta('_paykka_pay_for_order', true) === 'yes';
    }

    /**
     * 支付成功后收尾：只有购物车结账才清空购物车与结账 session。
     *
     * @param \WC_Order $order
     */
    private static function finishSuccess($order)
    {
        if (!function_exists('WC')) {
            return;
        }
        if (!self::isPayForOrder($order) && WC()->cart) {
            WC()->cart->empty_cart();
        }
        if (WC()->session) {
            WC()->session->__unset('paykka_checkout_order_id');
        }
    }

    /**
     * 未付成功时的重试地址：order-pay 来的回该订单付款页，否则回结账页。
     *
     * @param \WC_Order $order
     * @return string
     */
    private static function getRetryUrl($order)
    {
        if (self::isPayForOrder($order) && $order->needs_payment()) {
            return $order->get_checkout_payment_url();
        }
        return wc_get_checkout_url();
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
            self::finishSuccess($order);
            self::redirectAfterPayment($order, $order->get_checkout_order_received_url(), true);
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

            // 收银台（session）级结果不能决定支付结果，必须拿到网关交易（order_id）
            $is_transaction = $paykkaPaymentHelper->getQueryResultLevel($query_result) === 'transaction';

            if (($is_transaction && in_array($status, array('SUCCESS', 'AUTHORIZED'), true))
                || ($order && in_array($order->get_status(), array('processing', 'completed', 'on-hold'), true))
            ) {
                self::finishSuccess($order);
                self::redirectAfterPayment($order, $order->get_checkout_order_received_url(), true);
                exit;
            }

            // 查到了但未成功（FAILURE / 收银台待支付 等）：回结账页，不进感谢页
            if (function_exists('wc_add_notice')) {
                if ($is_transaction && ($status === 'FAILURE' || $status === 'CANCELED')) {
                    wc_add_notice(__('The payment was not successful. Please try again or choose another payment method.', 'paykka-for-woocommerce'), 'error');
                } elseif (!$is_transaction) {
                    wc_add_notice(__('We have not received your payment yet. Please start the payment again.', 'paykka-for-woocommerce'), 'notice');
                } else {
                    wc_add_notice(__('Your payment is still being confirmed. Please refresh in a moment or contact support.', 'paykka-for-woocommerce'), 'notice');
                }
            }
            self::redirectAfterPayment($order, self::getRetryUrl($order), false);
            exit;
        }

        if (function_exists('paykka_is_debug') && paykka_is_debug()) {
            error_log('[Paykka Callback] Query failed order_id=' . $order_id . ' result=' . wp_json_encode($query_result));
        }
        // 顾客可能多次返回，同一条备注只写一次
        $failed_note = 'PayKKa callback query failed, keep current status.';
        if ((string) $order->get_meta('_paykka_last_callback_note', true) !== $failed_note) {
            $order->update_meta_data('_paykka_last_callback_note', $failed_note);
            $order->add_order_note($failed_note);
        }
        $order->save();
        if (function_exists('wc_add_notice')) {
            wc_add_notice(__('We cannot confirm the payment result right now. Please try again later or contact support.', 'paykka-for-woocommerce'), 'error');
        }
        self::redirectAfterPayment($order, self::getRetryUrl($order), false);
        exit;
    }

    /**
     * WeChat 弹窗支付：在 popup 内回调时通知 opener 并关闭窗口；否则整页跳转。
     *
     * @param \WC_Order $order
     * @param string    $url
     * @param bool      $paid
     */
    private static function redirectAfterPayment($order, $url, $paid)
    {
        $is_wechat_popup = $order
            && $order->get_payment_method() === 'paykka-wechat'
            && $order->get_meta('_paykka_wechat_popup', true) === 'yes';

        if (!$is_wechat_popup) {
            wp_safe_redirect($url);
            return;
        }

        $safe_url = esc_url_raw($url);
        $payload  = wp_json_encode(array(
            'source'    => 'paykka-wechat',
            'type'      => $paid ? 'paid' : 'retry',
            'returnUrl' => $safe_url,
        ));

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>PayKKa</title></head><body>';
        echo '<script>';
        echo 'var payload = ' . $payload . ';';
        echo 'try { if (window.opener && !window.opener.closed) { window.opener.postMessage(payload, "*"); } } catch (e) {}';
        echo 'if (payload.type === "paid") { try { window.close(); } catch (e) {} setTimeout(function(){ window.location = payload.returnUrl; }, 300); }';
        echo 'else { window.location = payload.returnUrl; }';
        echo '</script>';
        echo '<p><a href="' . esc_url($safe_url) . '">Continue</a></p>';
        echo '</body></html>';
    }
}
