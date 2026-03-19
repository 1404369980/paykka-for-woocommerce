<?php
/**
 * Paykka_Credit_Card_Gateway class.
 *
 * @extends WC_Payment_Gateway
 */

use lib\Paykka\Request\PaykkaRequestHandler;
use lib\Paykka\Request\PaykkaWebHookHandler;
use lib\Paykka\Request\PaykkaCallBackHandler;



class Paykka_Drop_In_Gateway extends WC_Payment_Gateway
{
    private $merchant_id;

    public function __construct()
    {
        $this->id = 'paykka-drop-in';
        $this->has_fields = false;
        // $this->version = '8.2.0';
        $this->icon = '';
        $this->method_description = __('PayKKa Drop In Card payments.', 'paykka-for-woocommerce');
        $this->method_title = __('PayKKa Drop In Card', 'paykka-for-woocommerce');

        $this->title = __('PayKKa Drop In Card', 'paykka-for-woocommerce');
        $this->description = __('Use PayKKa Drop In Card to securely pay with your card.', 'paykka-for-woocommerce');

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

        // add_action('rest_api_init', [$this, 'register_drop_in_endpoint'], 30);
        add_action('rest_api_init', [$this, 'register_drop_in_session_endpoint'], 30);

    }


    public function register_drop_in_session_endpoint()
    {
        register_rest_route('paykka/v1', '/drop-in/session', array(
            'methods' => 'POST',
            'callback' => array($this, 'handler_drop_in_session'),
            'permission_callback' => '__return_true',
        ));
    }

    public function handler_drop_in_session(\WP_REST_Request $request)
    {
        if (!is_user_logged_in()) {
            return new \WP_REST_Response(array('success' => false, 'message' => __('Please log in first', 'paykka-for-woocommerce')), 401);
        }
        $params = $request->get_params();
        $encrypted = isset($params['encrypted_card_data']) ? $params['encrypted_card_data'] : null;
        if (empty($encrypted)) {
            return new \WP_REST_Response(array('success' => false, 'message' => __('Missing card data', 'paykka-for-woocommerce')), 400);
        }
        $user_id = get_current_user_id();
        set_transient('encrypted_card_data' . $user_id, $encrypted, 20);
        return new \WP_REST_Response(array('success' => true));
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
        $order->update_status('pending', 'processing');

        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';
        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaWebHookHandler.php';
        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaCallBackHandler.php';

        $paykkaPaymentHelper = new PaykkaRequestHandler();
        $response_data = $paykkaPaymentHelper->buildSessionId($order, 'DROP_IN');

        if (empty($response_data) || !isset($response_data['ret_code']) || $response_data['ret_code'] !== '000000') {
            return array(
                'result' => 'failure',
                'message' => isset($response_data['ret_msg']) ? $response_data['ret_msg'] : __('Payment session failed', 'paykka-for-woocommerce')
            );
        }
        $session_id = $response_data['data']['session_id'];

        $order->update_status('pending', '等待跳转到收银台');
        WC()->cart->empty_cart();

        $callback_url = PaykkaCallBackHandler::getCallbackUrl($order->get_id());
        $notify_url = PaykkaWebHookHandler::getWebHookUrl();

        WC()->session->__unset('woocommerce_order_id');
        WC()->session->__unset('paykka_dropin_session_id');
        WC()->session->__unset('paykka_dropin_client_key');
        WC()->session->__unset('paykka_dropin_callback_url');
        WC()->session->__unset('paykka_dropin_notify_url');

        WC()->session->set('woocommerce_order_id', $order_id);
        WC()->session->set('paykka_dropin_session_id', $session_id);
        WC()->session->set('paykka_dropin_client_key', $this->client_key);
        WC()->session->set('paykka_dropin_callback_url', $callback_url);
        WC()->session->set('paykka_dropin_notify_url', $notify_url);

        ob_end_clean();

        return array(
            'result'   => 'success',
            'redirect' => paykka_get_payment_url('dropin'),
        );

    }


    public function handler_drop_in($order_id)
    {
        require_once PAYKKA_PLUGIN_PATH . 'classes/lib/Paykka/Request/PaykkaRequestHandler.php';

        if (!is_user_logged_in()) {
            throw new \Exception(__('Please log in first', 'paykka-for-woocommerce'));
        }
        $user_id = get_current_user_id();
        $encrypted_card_data = get_transient('encrypted_card_data' . $user_id);
        if (empty($encrypted_card_data) || !is_array($encrypted_card_data) || empty($encrypted_card_data['encryptedCardNumber'])) {
            throw new \Exception(__('Payment card data expired or invalid', 'paykka-for-woocommerce'));
        }

        $order = wc_get_order($order_id);
        if (!$order || !$order->get_id()) {
            throw new \Exception(__('Order not found', 'paykka-for-woocommerce'));
        }

        $paykkaPaymentHelper = new PaykkaRequestHandler();
        $response_data = $paykkaPaymentHelper->handlerCardPayment($order, $encrypted_card_data);

        if (!is_array($response_data)) {
            throw new \Exception(__('Invalid API response', 'paykka-for-woocommerce'));
        }
        if (isset($response_data['ret_code']) && $response_data['ret_code'] === '000000') {
            if (!empty($response_data['order_id'])) {
                $order->update_meta_data('_paykka_order_id', sanitize_text_field((string) $response_data['order_id']));
            }
            if (!in_array($order->get_status(), array('processing', 'completed', 'on-hold'), true)) {
                $order->update_status('on-hold', __('PayKKa payment accepted, awaiting confirmation', 'paykka-for-woocommerce'));
            } else {
                $order->add_order_note(__('PayKKa payment accepted, awaiting confirmation', 'paykka-for-woocommerce'));
            }
            $order->save();
            return;
        }
        $error_message = isset($response_data['ret_msg']) ? sanitize_text_field($response_data['ret_msg']) : __('Payment processing failed', 'paykka-for-woocommerce');
        if (function_exists('paykka_is_debug') && paykka_is_debug()) {
            error_log('[Paykka Drop-in] Order ' . $order_id . ' - ' . $error_message);
        }
        throw new \Exception($error_message);
    }
}