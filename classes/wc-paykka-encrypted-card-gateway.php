<?php
/**
 * Paykka_Credit_Card_Gateway class.
 *
 * @extends WC_Payment_Gateway
 */

use lib\Paykka\Request\PaykkaRequestHandler;
use lib\Paykka\Request\PaykkaWebHookHandler;
use lib\Paykka\Request\PaykkaCallBackHandler;
use lib\Paykka\Api\Browser;


class Paykka_Encrypted_Card_Gateway extends WC_Payment_Gateway
{
    private $merchant_id;

    public function __construct()
    {
        $this->id = 'paykka-encrypted-card';
        $this->has_fields = false;
        // $this->version = '8.2.0';
        $this->icon = '';
        $this->method_description = __('PayKKa Encrypted Card Gateway Card payments.', 'paykka-for-woocommerce');
        $this->method_title = __('PayKKa Encrypted Card', 'paykka-for-woocommerce');

        $this->title = __('PayKKa Encrypted Card', 'paykka-for-woocommerce');
        $this->description = __('Use PayKKa Paykka Encrypted Card Gateway Card to securely pay with your card.', 'paykka-for-woocommerce');

        $this->supports = array(
            'products',
            'refunds',
        );

        // 具有所有选项字段的方法
        $this->init_form_fields();

        // 加载设置。
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');
        $this->private_key = $this->testmode ? $this->get_option('sandbox_private_key') : $this->get_option('private_key');

        $this->publishable_key = $this->testmode ? $this->get_option('test_publishable_key') : $this->get_option('publishable_key');
        $this->merchant_id = $this->testmode ? $this->get_option('sandbox_merchant_id') : $this->get_option('merchant_id');
        $this->client_key = $this->get_option('client_key');
        // 这个动作挂钩保存设置
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        add_action('rest_api_init', [$this, 'register_encrypted_card_endpoint'], 30);
    }

    public function init_form_fields()
    {
    }

    public function process_admin_options()
    {
        parent::process_admin_options();
    }

    public function payment_fields()
    {
    }

    public function payment_scripts()
    {

        // echo '<script>console.log("准备下单")</script>';
    }

    public function validate_fields()
    {
        return true;
    }

    public function is_available()
    {
        // 检查支付方式是否已启用
        if ($this->enabled !== 'yes') {
            return false;
        }

        // 额外调试
        if (!is_checkout()) {
            // error_log('PayKKa Gateway is NOT available: Not on checkout page.');
            return true;
        }

        $test = $this->enabled === 'yes';
        // error_log('Payment gateway is available: ' . $this->enabled . ' :bool: ' . $test);
        return true;
    }

    public function receipt_page($order_id)
    {

    }


    public function process_payment($order_id)
    {

        ob_start();
        // 真实代码
        $order = wc_get_order($order_id);
        $order->update_status('pending', '等待跳转到收银台');
        WC()->cart->empty_cart();
        ob_end_clean();

        return array(
            'result'   => 'success',
            'redirect' => add_query_arg('order_id', $order_id, paykka_get_payment_url('card-encrypted')),
        );
    }



    public function handler_encrypted_card(\WP_REST_Request $request)
    {
        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';

        $params = $request->get_params();
        $order_id = isset($params['order_id']) ? absint($params['order_id']) : 0;
        $encrypted_card_data = isset($params['encrypted_card_data']) ? $params['encrypted_card_data'] : null;

        if (!$order_id || !is_array($encrypted_card_data)) {
            return new \WP_REST_Response(array('success' => false, 'message' => __('Invalid request', 'paykka-for-woocommerce')), 400);
        }

        $order = wc_get_order($order_id);
        if (!$order || !$order->get_id()) {
            return new \WP_REST_Response(array('success' => false, 'message' => __('Order not found', 'paykka-for-woocommerce')), 404);
        }

        $paykkaPaymentHelper = new PaykkaRequestHandler();
        $response_data = $paykkaPaymentHelper->handlerCardPayment($order, $encrypted_card_data);

        if (isset($response_data['ret_code']) && $response_data['ret_code'] === '000000') {
            $order->payment_complete();
            return new \WP_REST_Response(array(
                'success' => true,
                'redirect_url' => $this->get_return_url($order)
            ));
        }

        $error_message = isset($response_data['ret_msg']) ? sanitize_text_field($response_data['ret_msg']) : __('Payment processing failed', 'paykka-for-woocommerce');
        if (function_exists('paykka_is_debug') && paykka_is_debug() && is_array($response_data)) {
            error_log('[Paykka Encrypted Card Error] ' . wp_json_encode($response_data));
        }
        return new \WP_REST_Response(array('success' => false, 'message' => $error_message));
    }

    public function register_encrypted_card_endpoint()
    {
        register_rest_route('paykka/v1', '/encrypted_card', array(
            'methods' => 'POST',
            'callback' => array($this, 'handler_encrypted_card'),
            'permission_callback' => '__return_true',
        ));
    }
}