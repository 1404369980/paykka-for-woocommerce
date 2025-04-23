<?php
/**
 * Paykka_Credit_Card_Gateway class.
 *
 * @extends WC_Payment_Gateway
 */

use lib\Paykka\Request\PaykkaRequestHandler;
use lib\Paykka\Request\PaykkaWebHookHandler;
use lib\Paykka\Request\PaykkaCallBackHandler;


class Paykka_Google_Pay_Gateway extends WC_Payment_Gateway
{
    private $merchant_id;

    public function __construct()
    {
        $this->id = 'paykka-google-pay';
        $this->has_fields = false;
        // $this->version = '8.2.0';
        $this->icon = '';
        $this->method_description = __('PayKKa Google Pay Gateway payments.', 'paykka-for-woocommerce');
        $this->method_title = __('PayKKa Google Pay', 'paykka-for-woocommerce');

        $this->title = __('PayKKa Google Pay', 'paykka-for-woocommerce');
        $this->description = __('Use PayKKa Paykka Google Pay Gateway Card to securely pay with your card.', 'paykka-for-woocommerce');

        $this->supports = array(
            'products',
            'refunds',
        );

        // 具有所有选项字段的方法
        $this->init_form_fields();

        // 加载设置。
        $this->init_settings();

        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');
        $this->private_key = $this->testmode ? $this->get_option('sandbox_private_key') : $this->get_option('private_key');

        $this->publishable_key = $this->testmode ? $this->get_option('test_publishable_key') : $this->get_option('publishable_key');
        $this->merchant_id = $this->testmode ? $this->get_option('merchant_id') : $this->get_option('sandbox_merchant_id');
        $this->client_key = $this->get_option('client_key');
        // 这个动作挂钩保存设置
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
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
        // 1. 获取原始输入数据
        $raw_post = file_get_contents('php://input');
        $request_data = json_decode($raw_post, true);

        error_log('Process Payment Request: ' . print_r($request_data, true));

        // 检查数据是否有效
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($request_data)) {
            throw new Exception('Invalid JSON data received');
        }

        // 提取 payment_google_data
        $payment_google_data = null;

        foreach ($request_data['payment_data'] as $payment_item) {
            if ($payment_item['key'] === 'payment_google_data') {
                $payment_google_data = json_decode($payment_item['value'], true);

                // 检查内层JSON是否有效
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid payment_google_data JSON');
                }
                break;
            }
        }

        if (!$payment_google_data) {
            throw new Exception('payment_google_data not found in request');
        }

        if (empty($payment_google_data['paymentMethodData']) || empty($payment_google_data['paymentMethodData']['tokenizationData'])) {
            throw new Exception('payment_google_data token not found in request');
        }

        $google_token = $payment_google_data['paymentMethodData']['tokenizationData']['token'];
        error_log('google_token: ' . $google_token);

        // 真实代码
        $order = wc_get_order($order_id);
        $order->update_status('pending', '支付中');

        require_once FENGQIAO_PAYKKA_URL . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        $paykkaPaymentHelper = new PaykkaRequestHandler();
        error_log("PaykkaRequestHandler: \n");
        $response_data = $paykkaPaymentHelper->handlerGooglePayPayment($order, $google_token);
        error_log('response_data: ' . $response_data);
        ob_end_clean();


        if (isset($response_data['ret_code']) && $response_data['ret_code'] === '000000') {
            return [
                'result' => 'success', 
                'redirect' => $order->get_checkout_order_received_url()];
        }else{
            return [
                'result' => 'failure',
                'message' => $response_data['ret_msg']
            ];
        }        
    }

}