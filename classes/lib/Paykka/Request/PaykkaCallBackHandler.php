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
     * Card：Create Session 时尚无 WC 订单；点下单绑定后再用 attempt_id 解析。
     */
    public static function getCallbackUrlByAttempt($attempt_id)
    {
        return add_query_arg(array(
            'wc-api'     => 'WC_Gateway_Paykka_Payment_callback',
            'attempt_id' => sanitize_text_field((string) $attempt_id),
        ), home_url('/'));
    }

    public function handle_payment_callback()
    {
        $order_id   = isset($_REQUEST['order_id']) ? absint($_REQUEST['order_id']) : 0;
        $attempt_id = !empty($_REQUEST['attempt_id']) ? sanitize_text_field(wp_unslash($_REQUEST['attempt_id'])) : '';

        // Card：attempt_id；Hosted：order_id
        if (!$order_id && $attempt_id !== '' && function_exists('paykka_resolve_order_id_by_attempt')) {
            $order_id = paykka_resolve_order_id_by_attempt($attempt_id);
        }

        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order || !$order->get_id()) {
            $this->redirect_checkout();
        }

        if ($attempt_id !== '' && !$this->is_valid_card_attempt($order, $attempt_id)) {
            $this->redirect_checkout();
        }

        if (in_array($order->get_status(), array('processing', 'completed'), true)) {
            $this->finish_success($order);
        }

        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        $helper = new PaykkaRequestHandler();
        $query_result = $helper->queryPaymentForOrder($order);

        if (is_array($query_result) && isset($query_result['ret_code']) && $query_result['ret_code'] === '000000') {
            $helper->syncOrderByQueryResult($order, $query_result, 'callback');
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
                $this->finish_success($order);
            }

            $error_code = '';
            if (!empty($query_result['error_code'])) {
                $error_code = (string) $query_result['error_code'];
            } elseif (!empty($query_result['data']['error_code'])) {
                $error_code = (string) $query_result['data']['error_code'];
            }

            // 收银台过期 / 5009：不走失败页，回结账由前端重建 Session；文案用接口返回描述
            $recover = ($status === 'EXPIRED' || $error_code === '4018' || $error_code === '5009');
            if ($recover) {
                if (function_exists('wc_add_notice')) {
                    $api_msg = '';
                    foreach (array(
                        array($query_result, 'error_description'),
                        array($query_result, 'error_msg'),
                        array($query_result, 'ret_msg'),
                        array(isset($query_result['data']) && is_array($query_result['data']) ? $query_result['data'] : array(), 'error_description'),
                        array(isset($query_result['data']) && is_array($query_result['data']) ? $query_result['data'] : array(), 'error_msg'),
                        array(isset($query_result['data']) && is_array($query_result['data']) ? $query_result['data'] : array(), 'msg'),
                    ) as $pair) {
                        $src = $pair[0];
                        $key = $pair[1];
                        if (!empty($src[$key]) && is_scalar($src[$key])) {
                            $api_msg = wp_strip_all_tags((string) $src[$key]);
                            break;
                        }
                    }
                    if ($api_msg !== '') {
                        wc_add_notice($api_msg, 'notice');
                    }
                }
                $this->redirect_checkout();
            }

            if (in_array($status, array('FAILURE', 'CANCELED'), true)) {
                $this->finish_failure(
                    $order,
                    __('支付未成功，请重试或更换支付方式。', 'paykka-for-woocommerce')
                );
            }

            if (function_exists('wc_add_notice')) {
                wc_add_notice(__('支付结果确认中，请稍候刷新或联系客服。', 'paykka-for-woocommerce'), 'notice');
            }
            $this->redirect_checkout();
        }

        if (function_exists('paykka_is_debug') && paykka_is_debug()) {
            error_log('[Paykka Callback] Query failed order_id=' . $order_id . ' result=' . wp_json_encode($query_result));
        }
        $order->add_order_note('PayKKa callback query failed, keep current status.');
        $order->save();
        if (function_exists('wc_add_notice')) {
            wc_add_notice(__('暂时无法确认支付结果，请稍后再试或联系客服。', 'paykka-for-woocommerce'), 'error');
        }
        $this->redirect_checkout();
    }

    /**
     * @param \WC_Order $order
     * @param string    $attempt_id
     * @return bool
     */
    private function is_valid_card_attempt($order, $attempt_id)
    {
        if ($order->get_payment_method() !== 'paykka-card') {
            return false;
        }
        $meta_attempt = (string) $order->get_meta('_paykka_attempt_id', true);
        return $meta_attempt === '' || hash_equals($meta_attempt, $attempt_id);
    }

    /**
     * @param \WC_Order $order
     */
    private function finish_success($order)
    {
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }
        if ($order && $order->get_payment_method() === 'paykka-card' && function_exists('paykka_clear_checkout_attempt_session')) {
            paykka_clear_checkout_attempt_session();
        }
        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }

    /**
     * FAILURE / CANCELED：跳转订单结果页（失败态）。
     * 注：EXPIRED / 5009 不走此路径，回结账重建收银台。
     *
     * @param \WC_Order $order
     * @param string    $message
     */
    private function finish_failure($order, $message = '')
    {
        if ($message !== '' && function_exists('wc_add_notice')) {
            wc_add_notice($message, 'error');
        }
        if ($order && $order->get_payment_method() === 'paykka-card' && function_exists('paykka_clear_checkout_attempt_session')) {
            paykka_clear_checkout_attempt_session();
        }
        $url = $order->get_checkout_order_received_url();
        $url = add_query_arg('paykka_result', 'failed', $url);
        wp_safe_redirect($url);
        exit;
    }

    private function redirect_checkout()
    {
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }
}
