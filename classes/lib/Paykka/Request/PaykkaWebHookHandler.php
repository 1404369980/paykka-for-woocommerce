<?php
namespace lib\Paykka\Request;

class PaykkaWebHookHandler
{
    public static $WEB_HOOK_URL = '/paykka/v1/webhook';

    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
    }

    public static function getWebHookUrl()
    {
        return rest_url(PaykkaWebHookHandler::$WEB_HOOK_URL);
    }

    public function register_webhook_endpoint()
    {
        register_rest_route('paykka/v1', '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_paykka_webhook_request'),
            'permission_callback' => '__return_true',
        ));
    }

    function handle_paykka_webhook_request($request)
    {
        $payload = $request->get_body();

        // 配置了平台公钥时必须验签（PayKKa SHA256_WITH_RSA）
        $verify = $this->verify_webhook_request($request, $payload);
        if ($verify === false) {
            if (function_exists('paykka_is_debug') && paykka_is_debug()) {
                error_log('[Paykka Webhook] Signature verification failed');
            }
            return new \WP_REST_Response(array('ret_code' => '401', 'ret_msg' => 'Invalid signature'), 401);
        }

        $data = json_decode($payload, true);
        $this->process_payment_webhook($data);
        return new \WP_REST_Response(array('ret_code' => '000000', 'ret_msg' => 'Success'), 200);
    }

    /**
     * @param \WP_REST_Request $request
     * @param string           $raw_body
     * @return bool|null true=通过；false=失败；null=未配置公钥（跳过验签，兼容旧配置）
     */
    private function verify_webhook_request($request, $raw_body)
    {
        $public_key = trim((string) get_option('paykka_platform_public_key', ''));
        if ($public_key === '') {
            return null;
        }

        $timestamp = $request->get_header('x-paykka-timestamp');
        $nonce     = $request->get_header('x-paykka-nonce');
        $sign      = $request->get_header('x-paykka-sign');
        if ($timestamp === '' || $nonce === '' || $sign === '' || $timestamp === null || $nonce === null || $sign === null) {
            return false;
        }

        $uri_path = '';
        if (!empty($_SERVER['REQUEST_URI'])) {
            $uri_path = (string) parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        }
        if ($uri_path === '') {
            $uri_path = '/wp-json/paykka/v1/webhook';
        }

        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        $handler = new PaykkaRequestHandler();
        return $handler->verifyPaykkaSignature(
            'POST',
            $uri_path,
            (string) $timestamp,
            (string) $nonce,
            (string) $raw_body,
            (string) $sign,
            $public_key
        );
    }

    public function process_payment_webhook($webHookOrder)
    {
        if (!is_array($webHookOrder)) {
            if (function_exists('paykka_is_debug') && paykka_is_debug()) {
                error_log('[Paykka Webhook] Invalid payload: ' . wp_json_encode($webHookOrder));
            }
            return;
        }

        if ($this->is_refund_webhook_payload($webHookOrder)) {
            $this->process_refund_webhook($webHookOrder);
            return;
        }

        $order = $this->get_order_for_payment_webhook($webHookOrder);
        if (!$order || !$order->get_id()) {
            if (function_exists('paykka_is_debug') && paykka_is_debug()) {
                error_log('[Paykka Webhook] Order not found: ' . wp_json_encode($webHookOrder));
            }
            return;
        }

        $order_id = $order->get_id();
        $payment_status = isset($webHookOrder['status']) ? (string) $webHookOrder['status'] : '';

        // 若通知带了 GW order_id / session_id / trans_id，先写入 meta，便于后续查单
        if (!empty($webHookOrder['order_id'])) {
            $order->update_meta_data('_paykka_order_id', sanitize_text_field((string) $webHookOrder['order_id']));
        }
        if (!empty($webHookOrder['session_id'])) {
            $order->update_meta_data('_paykka_session_id', sanitize_text_field((string) $webHookOrder['session_id']));
        }
        if (!empty($webHookOrder['trans_id'])) {
            $ts = trim((string) $webHookOrder['trans_id']);
            if ($ts !== '') {
                $order->update_meta_data('_paykka_trans_id', sanitize_text_field($ts));
            }
        }
        $order->save();

        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        $paykkaPaymentHelper = new PaykkaRequestHandler();
        $query_result = $paykkaPaymentHelper->queryPaymentForOrder($order);
        if (is_array($query_result) && isset($query_result['ret_code']) && $query_result['ret_code'] === '000000') {
            $paykkaPaymentHelper->syncOrderByQueryResult($order, $query_result, 'webhook');
            return;
        }

        if (function_exists('paykka_is_debug') && paykka_is_debug()) {
            error_log('[Paykka Webhook] Query failed fallback to payload status. order_id=' . $order_id . ' query=' . wp_json_encode($query_result));
        }
        $fallback_result = array(
            'status' => $payment_status,
        );
        if (!empty($webHookOrder['order_id'])) {
            $fallback_result['order_id'] = (string) $webHookOrder['order_id'];
        }
        $paykkaPaymentHelper->syncOrderByQueryResult($order, $fallback_result, 'webhook-fallback');
    }

    /**
     * 支付 Webhook → Woo 订单：
     * 1) PayKKa GW order_id → meta _paykka_order_id
     * 2) payload.trans_id → meta _paykka_trans_id
     * 3) 纯数字 trans_id → WC 订单号（Hosted）
     * 4) 数字前缀（如 101c2026…）→ WC 订单号（Component）
     *
     * @param array $payload
     * @return \WC_Order|null
     */
    private function get_order_for_payment_webhook($payload)
    {
        if (!is_array($payload)) {
            return null;
        }

        foreach (array('order_id') as $gw_key) {
            if (empty($payload[$gw_key])) {
                continue;
            }
            $gw_id = sanitize_text_field((string) $payload[$gw_key]);
            if ($gw_id === '' || strpos($gw_id, 'GW') !== 0) {
                // 非 GW 前缀时也尝试 meta（兼容）
            }
            if ($gw_id === '') {
                continue;
            }
            $orders = wc_get_orders(array(
                'limit'      => 1,
                'meta_key'   => '_paykka_order_id',
                'meta_value' => $gw_id,
                'return'     => 'objects',
            ));
            if (!empty($orders) && is_a($orders[0], 'WC_Order')) {
                return $orders[0];
            }
        }

        if (!empty($payload['trans_id'])) {
            $ts = trim((string) $payload['trans_id']);
            if ($ts !== '') {
                $orders = wc_get_orders(array(
                    'limit'      => 1,
                    'meta_key'   => '_paykka_trans_id',
                    'meta_value' => $ts,
                    'return'     => 'objects',
                ));
                if (!empty($orders) && is_a($orders[0], 'WC_Order')) {
                    return $orders[0];
                }

                if (ctype_digit($ts)) {
                    $order = wc_get_order(absint($ts));
                    if ($order && $order->get_id()) {
                        return $order;
                    }
                }

                // Component: 101c20260901… → 101
                if (preg_match('/^(\d+)/', $ts, $m)) {
                    $oid = absint($m[1]);
                    if ($oid > 0) {
                        $order = wc_get_order($oid);
                        if ($order && $order->get_id()) {
                            return $order;
                        }
                    }
                }
            }
        }

        if (!empty($payload['session_id'])) {
            $sid = sanitize_text_field((string) $payload['session_id']);
            if ($sid !== '') {
                $orders = wc_get_orders(array(
                    'limit'      => 1,
                    'meta_key'   => '_paykka_session_id',
                    'meta_value' => $sid,
                    'return'     => 'objects',
                ));
                if (!empty($orders) && is_a($orders[0], 'WC_Order')) {
                    return $orders[0];
                }
            }
        }

        return null;
    }

    private function is_refund_webhook_payload($payload)
    {
        return $this->parse_refund_webhook_event($payload) !== '';
    }

    /**
     * @return string 空=非退款通知；success|failure|other
     */
    private function parse_refund_webhook_event($payload)
    {
        if (!is_array($payload)) {
            return '';
        }
        $event_type = isset($payload['event_type']) ? strtoupper(trim((string) $payload['event_type'])) : '';
        $notify_type = isset($payload['notify_type']) ? strtoupper(trim((string) $payload['notify_type'])) : '';
        foreach (array($event_type, $notify_type) as $t) {
            if ($t === 'REFUND.SUCCESS') {
                return 'success';
            }
            if ($t === 'REFUND.FAILURE') {
                return 'failure';
            }
        }
        if ($event_type !== '' && strpos($event_type, 'REFUND') !== false) {
            return 'other';
        }
        if ($notify_type !== '' && strpos($notify_type, 'REFUND') !== false) {
            return 'other';
        }
        if (!empty($payload['refund_trans_id']) || !empty($payload['refund_order_id'])) {
            return 'other';
        }
        return '';
    }

    /**
     * @param array $payload
     * @return \WC_Order|null
     */
    private function get_order_for_refund_webhook($payload)
    {
        if (isset($payload['trans_id']) && $payload['trans_id'] !== '' && $payload['trans_id'] !== null) {
            $ts = trim((string) $payload['trans_id']);
            if ($ts !== '' && ctype_digit($ts)) {
                $oid = absint($ts);
                if ($oid > 0) {
                    $order = wc_get_order($oid);
                    if ($order && $order->get_id()) {
                        return $order;
                    }
                }
            }
            // Component 支付原单：退款通知 trans_id 可能是退款流水，也可能带原单前缀
            if ($ts !== '' && preg_match('/^(\d+)/', $ts, $m) && !ctype_digit($ts)) {
                // 非纯数字时优先不按订单号猜，走 GW meta
            }
        }
        foreach (array('order_id', 'ori_order_id') as $gw_key) {
            if (empty($payload[$gw_key])) {
                continue;
            }
            $gw_id = sanitize_text_field((string) $payload[$gw_key]);
            if ($gw_id === '') {
                continue;
            }
            $orders = wc_get_orders(array(
                'limit'      => 1,
                'meta_key'   => '_paykka_order_id',
                'meta_value' => $gw_id,
                'return'     => 'objects',
            ));
            if (!empty($orders) && is_a($orders[0], 'WC_Order')) {
                return $orders[0];
            }
        }
        return null;
    }

    private function process_refund_webhook($webHookOrder)
    {
        $refund_event = $this->parse_refund_webhook_event($webHookOrder);
        $event_label = isset($webHookOrder['event_type']) ? (string) $webHookOrder['event_type'] : '';
        if ($event_label === '' && isset($webHookOrder['notify_type'])) {
            $event_label = (string) $webHookOrder['notify_type'];
        }
        $sync_source = $event_label !== '' ? 'refund-webhook:' . $event_label : 'refund-webhook';

        $order = $this->get_order_for_refund_webhook($webHookOrder);
        if (!$order || !$order->get_id()) {
            if (function_exists('paykka_is_debug') && paykka_is_debug()) {
                error_log('[Paykka Refund Webhook] Order not found. Payload: ' . wp_json_encode($webHookOrder));
            }
            return;
        }
        $order_id = $order->get_id();

        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        $paykkaPaymentHelper = new PaykkaRequestHandler();
        $refund_trans_id = isset($webHookOrder['refund_trans_id']) ? trim((string) $webHookOrder['refund_trans_id']) : '';
        if ($refund_trans_id === '' && isset($webHookOrder['trans_id'])) {
            $ts = trim((string) $webHookOrder['trans_id']);
            if ($ts !== '' && !ctype_digit($ts)) {
                $refund_trans_id = $ts;
            }
        }
        $refund_order_id = isset($webHookOrder['refund_order_id']) ? (string) $webHookOrder['refund_order_id'] : '';
        $refund_query_result = $paykkaPaymentHelper->queryRefund($refund_trans_id, $refund_order_id);
        if (is_array($refund_query_result) && isset($refund_query_result['ret_code']) && $refund_query_result['ret_code'] === '000000') {
            $paykkaPaymentHelper->syncOrderByRefundQueryResult($order, $refund_query_result, $sync_source);
            return;
        }

        if (function_exists('paykka_is_debug') && paykka_is_debug()) {
            error_log('[Paykka Refund Webhook] Query failed order_id=' . $order_id . ' query=' . wp_json_encode($refund_query_result));
        }
        $fallback_status = isset($webHookOrder['status']) ? strtoupper((string) $webHookOrder['status']) : '';
        if ($fallback_status === '') {
            if ($refund_event === 'success') {
                $fallback_status = 'SUCCESS';
            } elseif ($refund_event === 'failure') {
                $fallback_status = 'FAILURE';
            }
        }
        $fallback_refund = array(
            'status' => $fallback_status,
            'refund_order_id' => $refund_order_id,
            'refund_trans_id' => $refund_trans_id,
            'amount' => isset($webHookOrder['amount']) ? (int) $webHookOrder['amount'] : 0,
        );
        if (!empty($webHookOrder['error_code'])) {
            $fallback_refund['error_code'] = (string) $webHookOrder['error_code'];
        }
        if (!empty($webHookOrder['error_description'])) {
            $fallback_refund['error_description'] = (string) $webHookOrder['error_description'];
        }
        $paykkaPaymentHelper->syncOrderByRefundQueryResult($order, $fallback_refund, $sync_source . '-fallback');
    }
}
