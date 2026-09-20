<?php
/**
 * Paykka WeChat Pay gateway — Hosted Session with WechatPayGlobal, popup checkout.
 *
 * @extends WC_Payment_Gateway
 */

use lib\Paykka\Request\PaykkaRequestHandler;

class Paykka_Wechat_Gateway extends WC_Payment_Gateway
{
    /** @var string */
    public $version = '';

    public function __construct()
    {
        $this->id                 = 'paykka-wechat';
        $this->has_fields         = false;
        $this->version            = '1.5.18';
        $this->icon               = '';
        $this->method_title       = __('Paykka WeChat Pay', 'paykka-for-woocommerce');
        $this->method_description = __('Creates a PayKKa Hosted checkout limited to WeChat Pay, then opens it in a popup after place order (PayPal-style).', 'paykka-for-woocommerce');
        $this->supports           = array('products', 'refunds');

        $this->init_form_fields();
        $this->init_settings();

        // 必须走 WC 标准 settings（woocommerce_paykka-wechat_settings），付款列表页开关才生效
        $this->enabled     = $this->get_option('enabled', 'no');
        $this->title       = $this->get_option('title', __('WeChat Pay', 'paykka-for-woocommerce'));
        $this->description = $this->get_option(
            'description',
            __('Pay with WeChat Pay in a secure popup window.', 'paykka-for-woocommerce')
        );

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_api_paykka_wechat_status', array($this, 'ajax_payment_status'));
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_checkout_script'));
        add_action('woocommerce_order_refunded', 'paykka_attach_refund_link_on_order_refunded', 10, 2);
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled'     => array(
                'title'   => __('Enable/Disable', 'paykka-for-woocommerce'),
                'type'    => 'checkbox',
                'label'   => __('Enable Paykka WeChat Pay', 'paykka-for-woocommerce'),
                'default' => 'no',
            ),
            'title'       => array(
                'title'       => __('Title', 'paykka-for-woocommerce'),
                'type'        => 'text',
                'description' => __('Payment method name shown at checkout.', 'paykka-for-woocommerce'),
                'default'     => __('WeChat Pay', 'paykka-for-woocommerce'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'paykka-for-woocommerce'),
                'type'        => 'textarea',
                'description' => __('Payment method description shown at checkout.', 'paykka-for-woocommerce'),
                'default'     => __('Pay with WeChat Pay in a secure popup window.', 'paykka-for-woocommerce'),
                'desc_tip'    => true,
            ),
        );
    }

    public function admin_options()
    {
        echo '<h2>' . esc_html($this->get_method_title()) . '</h2>';
        echo '<p>' . esc_html($this->get_method_description()) . '</p>';
        echo '<p>' . esc_html__(
            'API keys are shared with Paykka Hosted (WooCommerce → Settings → Payments → Paykka Hosted → Standard payments).',
            'paykka-for-woocommerce'
        ) . '</p>';
        echo '<table class="form-table">';
        echo $this->generate_settings_html($this->get_form_fields(), false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</table>';
    }

    public function process_admin_options()
    {
        // 写入 woocommerce_paykka-wechat_settings，与付款列表页开关同一数据源
        parent::process_admin_options();
    }

    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }
    }

    public function is_available()
    {
        return parent::is_available();
    }

    /**
     * Classic checkout: load popup helper so place-order can open session_url without leaving the page.
     */
    public function maybe_enqueue_checkout_script()
    {
        if (is_admin() || !$this->is_available()) {
            return;
        }
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        wp_enqueue_style(
            'paykka-wechat-checkout',
            PAYKKA_PLUGIN_URL . 'assets/css/checkout-wechat.css',
            array(),
            $this->version . '.1'
        );
        wp_enqueue_script(
            'paykka-wechat-checkout',
            PAYKKA_PLUGIN_URL . 'assets/js/checkout-wechat.js',
            array('jquery'),
            $this->version . '.1',
            true
        );
        wp_localize_script(
            'paykka-wechat-checkout',
            'PaykkaWechatCheckout',
            array(
                'gatewayId' => $this->id,
                'statusUrl' => add_query_arg('wc-api', 'paykka_wechat_status', home_url('/')),
                'i18n'      => array(
                    'waiting'      => __('Complete WeChat Pay in the popup window…', 'paykka-for-woocommerce'),
                    'popupBlocked' => __('Please allow popups for this site to complete WeChat Pay.', 'paykka-for-woocommerce'),
                    'closed'       => __('Payment window closed. Checking payment status…', 'paykka-for-woocommerce'),
                    'failed'       => __('WeChat Pay was not completed. Please try again.', 'paykka-for-woocommerce'),
                    'reopen'       => __('Reopen WeChat Pay', 'paykka-for-woocommerce'),
                ),
            )
        );
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order || !$order->get_id()) {
            return array('result' => 'failure', 'message' => __('Invalid order', 'paykka-for-woocommerce'));
        }

        $trans_id = function_exists('paykka_begin_payment_attempt')
            ? paykka_begin_payment_attempt($order, 'wechat')
            : '';
        if ($trans_id === '') {
            wc_add_notice(__('Unable to start a new PayKKa WeChat payment for this order.', 'paykka-for-woocommerce'), 'error');
            return array('result' => 'failure', 'message' => __('Unable to start payment', 'paykka-for-woocommerce'));
        }

        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        $helper = new PaykkaRequestHandler();
        $response_data = $helper->buildWechatSession($order);

        if (function_exists('paykka_is_debug') && paykka_is_debug()) {
            error_log('[Paykka WeChat] process_payment order_id=' . $order_id . ' response=' . wp_json_encode($response_data));
        }

        if ($response_data === null) {
            wc_add_notice(__('Payment request failed. Please try again or choose another payment method.', 'paykka-for-woocommerce'), 'error');
            return array('result' => 'failure', 'message' => __('Payment request failed', 'paykka-for-woocommerce'));
        }

        if (!isset($response_data['ret_code']) || $response_data['ret_code'] !== '000000') {
            $msg = isset($response_data['ret_msg']) ? $response_data['ret_msg'] : __('Payment session failed', 'paykka-for-woocommerce');
            wc_add_notice($msg, 'error');
            return array('result' => 'failure', 'message' => $msg);
        }

        $session_url = isset($response_data['data']['session_url']) ? trim($response_data['data']['session_url']) : '';
        $session_id  = isset($response_data['data']['session_id']) ? trim((string) $response_data['data']['session_id']) : '';
        if ($session_url === '') {
            wc_add_notice(__('Payment session created but checkout URL is missing. Please contact support.', 'paykka-for-woocommerce'), 'error');
            return array('result' => 'failure', 'message' => __('Missing session URL', 'paykka-for-woocommerce'));
        }

        $order->update_meta_data('_paykka_session_id', sanitize_text_field($session_id));
        $order->update_meta_data('_paykka_session_url', esc_url_raw($session_url));
        $order->update_meta_data('_paykka_wechat_popup', 'yes');
        $order->save();

        if (function_exists('paykka_add_place_order_note')) {
            paykka_add_place_order_note($order, 'wechat', array(
                'trans_id'   => (string) $order->get_meta('_paykka_trans_id', true),
                'session_id' => $session_id,
            ));
        }

        WC()->cart->empty_cart();

        // 不跳转页面：redirect 仅用 hash，前端拦截后弹窗支付并轮询状态
        return array(
            'result'              => 'success',
            'redirect'            => '#paykka-wechat-pay',
            'paykka_wechat_popup' => $session_url,
            'paykka_order_id'     => (string) $order->get_id(),
            'paykka_order_key'    => $order->get_order_key(),
            'paykka_return_url'   => $order->get_checkout_order_received_url(),
            'paykka_status_url'   => add_query_arg(
                array(
                    'wc-api'    => 'paykka_wechat_status',
                    'order_id'  => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                ),
                home_url('/')
            ),
        );
    }

    /**
     * Receipt / pay page: open WeChat Hosted checkout in a popup and wait.
     *
     * @param int $order_id
     */
    public function receipt_page($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $session_url = trim((string) $order->get_meta('_paykka_session_url', true));
        if ($session_url === '') {
            echo '<p>' . esc_html__('WeChat Pay session is missing. Please place the order again.', 'paykka-for-woocommerce') . '</p>';
            return;
        }

        wp_enqueue_style(
            'paykka-wechat-checkout',
            PAYKKA_PLUGIN_URL . 'assets/css/checkout-wechat.css',
            array(),
            $this->version . '.1'
        );
        wp_enqueue_script(
            'paykka-wechat-checkout',
            PAYKKA_PLUGIN_URL . 'assets/js/checkout-wechat.js',
            array('jquery'),
            $this->version . '.1',
            true
        );
        wp_localize_script(
            'paykka-wechat-checkout',
            'PaykkaWechatCheckout',
            array(
                'gatewayId'  => $this->id,
                'autoStart'  => true,
                'popupUrl'   => $session_url,
                'orderId'    => $order->get_id(),
                'orderKey'   => $order->get_order_key(),
                'returnUrl'  => $order->get_checkout_order_received_url(),
                'statusUrl'  => add_query_arg(
                    array(
                        'wc-api'    => 'paykka_wechat_status',
                        'order_id'  => $order->get_id(),
                        'order_key' => $order->get_order_key(),
                    ),
                    home_url('/')
                ),
                'i18n'       => array(
                    'waiting'      => __('Complete WeChat Pay in the popup window…', 'paykka-for-woocommerce'),
                    'popupBlocked' => __('Please allow popups for this site to complete WeChat Pay.', 'paykka-for-woocommerce'),
                    'closed'       => __('Payment window closed. Checking payment status…', 'paykka-for-woocommerce'),
                    'failed'       => __('WeChat Pay was not completed. Please try again.', 'paykka-for-woocommerce'),
                    'reopen'       => __('Reopen WeChat Pay', 'paykka-for-woocommerce'),
                ),
            )
        );

        echo '<div id="paykka-wechat-receipt" class="paykka-wechat-waiting" data-paykka-wechat-receipt="1">';
        echo '<p class="paykka-wechat-waiting__text">' . esc_html__('Complete WeChat Pay in the popup window…', 'paykka-for-woocommerce') . '</p>';
        echo '<p><button type="button" class="button alt" id="paykka-wechat-reopen">' . esc_html__('Reopen WeChat Pay', 'paykka-for-woocommerce') . '</button></p>';
        echo '</div>';
    }

    /**
     * Poll endpoint: has this order been paid?
     */
    public function ajax_payment_status()
    {
        $order_id  = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $order_key = isset($_GET['order_key']) ? wc_clean(wp_unslash($_GET['order_key'])) : '';
        $order     = $order_id ? wc_get_order($order_id) : false;

        if (!$order || !$order->key_is_valid($order_key)) {
            wp_send_json_error(array('message' => 'invalid_order'), 403);
        }

        if ($order->get_payment_method() !== $this->id) {
            wp_send_json_error(array('message' => 'wrong_method'), 400);
        }

        $paid = $order->is_paid() || $order->has_status(array('processing', 'completed', 'on-hold'));
        if (!$paid) {
            // Soft refresh from PayKKa once in a while if still pending
            require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
            $helper = new PaykkaRequestHandler();
            $query  = $helper->queryPaymentForOrder($order);
            if (is_array($query) && isset($query['ret_code']) && $query['ret_code'] === '000000') {
                $helper->syncOrderByQueryResult($order, $query, 'wechat-poll');
                $order = wc_get_order($order_id);
                $paid  = $order && ($order->is_paid() || $order->has_status(array('processing', 'completed', 'on-hold')));
            }
        }

        wp_send_json_success(array(
            'paid'       => (bool) $paid,
            'status'     => $order ? $order->get_status() : '',
            'return_url' => $order ? $order->get_checkout_order_received_url() : '',
        ));
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        // Reuse Hosted refund path (same PayKKa transaction APIs).
        if (!class_exists('Paykka_Credit_Card_Gateway', false)) {
            require_once PAYKKA_PLUGIN_PATH . 'classes/wc-paykka-credit-card-gateway.php';
        }
        $hosted = new Paykka_Credit_Card_Gateway();
        return $hosted->process_refund($order_id, $amount, $reason);
    }
}
