<?php
/**
 * Paykka_Credit_Card_Gateway class.
 *
 * @extends WC_Payment_Gateway
 */

use lib\Paykka\Request\PaykkaRequestHandler;
use lib\Paykka\Request\PaykkaWebHookHandler;
use lib\Paykka\Request\PaykkaCallBackHandler;


class Paykka_Embedded_Gateway extends WC_Payment_Gateway
{
    private $merchant_id;

    public function __construct()
    {
        $this->id = 'paykka-embedded';
        $this->has_fields = false;
        $this->version = '8.2.0';
        $this->icon = '';
        $this->method_description = __('PayKKa Component payments.', 'paykka-for-woocommerce');
        $this->method_title = __('PayKKa Component', 'paykka-for-woocommerce');

        $this->title = __('PayKKa Component', 'paykka-for-woocommerce');
        $this->description = __('Use PayKKa Component to securely pay', 'paykka-for-woocommerce');

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
        $this->merchant_id = $this->testmode ? $this->get_option('merchant_id') : $this->get_option('sandbox_merchant_id');
        $this->client_key = $this->get_option('client_key');
        // 这个动作挂钩保存设置
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        // 回调
        add_action('woocommerce_api_wc_gateway_custom_payment_callback', array($this, 'handle_payment_callback'));
    }

    public function init_form_fields()
    {

        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'paykka-for-woocommerce'),
                'label' => __('Enable PayKKa Component Gateway', 'paykka-for-woocommerce'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'paykka-for-woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'paykka-for-woocommerce'),
                'default' => __('PayKKa Component', 'paykka-for-woocommerce'),
                'desc_tip' => true
            ),
            'description' => array(
                'title' => __('Description', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'paykka-for-woocommerce'),
                'default' => __('Pay securely with PayKKa Component.', 'paykka-for-woocommerce'),
                'desc_tip' => true
            ),
            'sandbox' => array(
                'title' => __('Sandbox', 'paykka-for-woocommerce'),
                'label' => __('Enable Sandbox Mode', 'paykka-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in sandbox mode using sandbox API keys (real payments will not be taken).', 'paykka-for-woocommerce'),
                'default' => 'yes'
            ),
            'sandbox_public_key' => array(
                'title' => __('Sandbox Public Key', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'sandbox_private_key' => array(
                'title' => __('Sandbox Private Key', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'sandbox_merchant_id' => array(
                'title' => __('Sandbox Merchant ID', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'public_key' => array(
                'title' => __('Live Public Key', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'private_key' => array(
                'title' => __('Live Private Key', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'merchant_id' => array(
                'title' => __('Live Merchant ID', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
                'default' => '',
                'desc_tip' => true
            ),
            'client_key' => array(
                'title' => __('Client Key', 'paykka-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Get your API keys from your PayKKa account.', 'paykka-for-woocommerce'),
                'default' => '',
                'desc_tip' => true
            ),

        );
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
        $order->update_status('pending', 'processing');

        // $order_data = json_encode($order->get_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        // error_log("Order Data: \n" . $order_data);

        // $cart_json = json_encode(WC()->cart->get_cart(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        // error_log("Cart Data: \n" . $cart_json);


        // $order->update_status('on-hold', __('Awaiting custom payment', 'woocommerce-custom-payment-gateway'));

        // 标记订单为已支付
        // $order->payment_complete();

        // 减少库存
        // wc_reduce_stock_levels($order_id);

        // 清空购物车
        // WC()->cart->empty_cart();

        // 返回成功和重定向链接

        // $url_code = $this->do_paykka_payment($order);

        require_once FENGQIAO_PAYKKA_URL . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        require_once FENGQIAO_PAYKKA_URL . '/classes/lib/Paykka/Request/PaykkaWebHookHandler.php';
        require_once FENGQIAO_PAYKKA_URL . '/classes/lib/Paykka/Request/PaykkaCallBackHandler.php';

        $paykkaPaymentHelper = new PaykkaRequestHandler();
        error_log("PaykkaRequestHandler: \n");
        $response_data = $paykkaPaymentHelper->buildSessionId($order, $this->merchant_id, $this->private_key);

        if(empty($response_data) || $response_data['ret_code'] !== '000000'){
            return [
                'result' => 'failure',
                'message' => $response_data['ret_msg']
            ];
        }
        $session_id = $response_data['data']['session_id'];

        error_log("session_id:" . $session_id);
        error_log("paykka_client_key:" . $this->client_key);


        $order->update_status('pending', '等待跳转到收银台');
        $page = get_page_by_path('paykka-embedded');
        error_log("url:" . get_permalink($page->ID));

        $callback_url = PaykkaCallBackHandler::getCallbackUrl($order->get_id());
        $notify_url = PaykkaWebHookHandler::getWebHookUrl();

        WC()->session->__unset('woocommerce_order_id');
        WC()->session->__unset('paykka_session_id');
        WC()->session->__unset('paykka_client_key');
        WC()->session->__unset('paykka_callback_url');
        WC()->session->__unset('paykka_notify_url');

        WC()->session->set('woocommerce_order_id', $order_id);
        WC()->session->set('paykka_session_id', $session_id);
        WC()->session->set('paykka_client_key', $this->client_key);
        WC()->session->set('paykka_callback_url', $callback_url);
        WC()->session->set('paykka_notify_url', $notify_url);



        error_log("WC()->session:" . WC()->session->get('paykka_session_id'));

        ob_end_clean();
        // print "请求url" . $url_code . "";

        // error_log("session url " . $url_code);
        // $url =  get_permalink($page->ID) ."xxx" .$order_id;

        return [
            'result' => 'success',
            'redirect' => get_permalink($page->ID),
        ];
    }

    public function handle_payment_callback()
    {
        ob_start(); // 开启输出缓冲区

        $order_id = $_REQUEST['order_id'];
        $order = wc_get_order($order_id);

        $order->payment_complete();
        WC()->cart->empty_cart();
        wc_reduce_stock_levels($order_id);

        $return_url = $this->get_return_url($order);

        ob_end_clean();
        wp_safe_redirect($return_url);
        exit;
    }
}