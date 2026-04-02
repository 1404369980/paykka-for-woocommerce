<?php
namespace lib\Paykka\Request;

class PaykkaWebHookHandler
{
    public static $WEB_HOOK_URL = '/paykka/v1/webhook';
    /**
     * 初始化 Webhook 处理类
     */
    public function __construct()
    {
        // 注册 Webhook 端点
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
    }

    public static function getWebHookUrl()
    {
        return rest_url(PaykkaWebHookHandler::$WEB_HOOK_URL);
    }


    /**
     * 注册 Webhook 端点
     */
    public function register_webhook_endpoint()
    {
        // error_log('Webhook endpoint registered'); // 调试日志
        register_rest_route('paykka/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_paykka_webhook_request'),
            'permission_callback' => '__return_true',
        ));
        // error_log('注册webhook成功');
    }


    function handle_paykka_webhook_request($request)
    {
        // 获取请求数据
        $payload = $request->get_body();
        $data = json_decode($payload, true);
        $this->process_payment_webhook($data);
        return new \WP_REST_Response(array('ret_code' => '000000', 'ret_msg' => 'Success'), 200);
    }


    // 处理 Webhook 数据
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

        if (empty($webHookOrder['trans_id'])) {
            if (function_exists('paykka_is_debug') && paykka_is_debug()) {
                error_log('[Paykka Webhook] Missing trans_id: ' . wp_json_encode($webHookOrder));
            }
            return;
        }

        $order_id = (int) $webHookOrder['trans_id'];
        $payment_status = isset($webHookOrder['status']) ? (string) $webHookOrder['status'] : '';

        $order = wc_get_order($order_id);
        if (!$order || !$order->get_id()) {
            if (function_exists('paykka_is_debug') && paykka_is_debug()) {
                error_log('[Paykka Webhook] Order not found: ' . $order_id);
            }
            return;
        }

        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        $paykkaPaymentHelper = new PaykkaRequestHandler();
        $query_result = $paykkaPaymentHelper->queryPayment((string) $order_id, '', '');
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
     * 是否为退款类 Webhook（含官方事件名与旧版字段兜底）。
     * - REFUND.SUCCESS：退款成功
     * - REFUND.FAILURE：退款失败
     * - 其他含 REFUND 的 event_type/notify_type，或仅有 refund 单号字段
     */
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
     * 根据退款 Webhook 解析 Woo 订单。
     *
     * 事件类型：REFUND.SUCCESS（成功）、REFUND.FAILURE（失败）；可与 notify_type 同值。
     *
     * PayKKa 退款通知里三个易混字段：
     * - trans_id：与退款接口入参 refund_trans_id 为同一商户退款流水号（如 R65-…）；仅当全为数字时才额外按 Woo 订单 ID 解析（兼容旧通知）。
     * - order_id：原支付在 PayKKa 的订单号（GW…），与 Woo 订单 meta _paykka_order_id 一致，用于反查本站订单。
     * - refund_order_id：本次退款在 PayKKa 的退款单号（RG…），用于 queryRefund、同步退款状态，不用于解析 Woo 订单。
     *
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

    /**
     * 处理退款 Webhook：收到通知后查询退款订单，再同步 Woo 订单。
     * 事件：REFUND.SUCCESS（成功）、REFUND.FAILURE（失败）；字段含义见 get_order_for_refund_webhook()。
     *
     * @param array $webHookOrder
     * @return void
     */
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
            // Webhook 的 trans_id 与退款接口的 refund_trans_id 一致；纯数字时视为 Woo 订单号，不当作 refund_trans_id 传入查询。
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


    /**
     * 验证 Webhook 签名（若 Paykka 提供 secret 可在后台配置后在此校验）
     * 当前 handle_paykka_webhook_request 未调用此方法，按需接入。
     */
    public function validate_webhook($data)
    {
        $secret = get_option('paykka_webhook_secret', '');
        if ($secret === '' || empty($_SERVER['HTTP_X_PAYKKA_SIGNATURE'])) {
            return false;
        }
        $expected = hash_hmac('sha256', is_string($data) ? $data : wp_json_encode($data), $secret);
        return hash_equals($expected, sanitize_text_field(wp_unslash($_SERVER['HTTP_X_PAYKKA_SIGNATURE'])));
    }

}