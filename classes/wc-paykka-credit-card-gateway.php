<?php

/**
 * WC_Gateway_PayPal_Credit_Card_Rest_AngellEYE class.
 *
 * @extends WC_Payment_Gateway
 */

class Paykka_Credit_Card_Gateway extends WC_Payment_Gateway
{

    public function __construct()
    {
        $this->id = 'paykka';
        $this->has_fields = false;
        $this->version = '8.2.0';
        $this->icon = apply_filters('woocommerce_paykka_icon', plugin_dir_url(__FILE__) . 'assets/images/payway.png');
        $this->method_description = __('Secure credit card payments via PayKKa.', 'paykka-for-woocommerce');
        $this->method_title = __('PayKKa Credit Card', 'paykka-for-woocommerce');

        $this->title = __('PayKKa Payment', 'paykka-for-woocommerce');
        $this->description = __('Use PayKKa to securely pay with your credit card.', 'paykka-for-woocommerce');

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
        $this->private_key = $this->testmode ? $this->get_option('test_private_key') : $this->get_option('private_key');
        $this->publishable_key = $this->testmode ? $this->get_option('test_publishable_key') : $this->get_option('publishable_key');

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
                'title' => __('Enable/Disable', 'paypal-for-woocommerce'),
                'label' => __('Enable Braintree Payment Gateway', 'paypal-for-woocommerce'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'paypal-for-woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'paypal-for-woocommerce'),
                'default' => __('Credit Card', 'paypal-for-woocommerce'),
                'desc_tip' => true
            ),
            'description' => array(
                'title' => __('Description', 'paypal-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'paypal-for-woocommerce'),
                'default' => __('Pay securely with your credit card.', 'paypal-for-woocommerce'),
                'desc_tip' => true
            ),
            'enable_braintree_drop_in' => array(
                'title' => __('Enable Drop-in Payment UI', 'paypal-for-woocommerce'),
                'label' => __('Enable Drop-in Payment UI', 'paypal-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Rather than showing a credit card form on your checkout, this shows the form on it\'s own page, thus making the process more secure and more PCI friendly.', 'paypal-for-woocommerce'),
                'default' => 'yes'
            ),
            'sandbox' => array(
                'title' => __('Sandbox', 'paypal-for-woocommerce'),
                'label' => __('Enable Sandbox Mode', 'paypal-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in sandbox mode using sandbox API keys (real payments will not be taken).', 'paypal-for-woocommerce'),
                'default' => 'yes'
            ),
            'sandbox_public_key' => array(
                'title' => __('Sandbox Public Key', 'paypal-for-woocommerce'),
                'type' => 'password',
                'description' => __('Get your API keys from your Braintree account.', 'paypal-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'sandbox_private_key' => array(
                'title' => __('Sandbox Private Key', 'paypal-for-woocommerce'),
                'type' => 'password',
                'description' => __('Get your API keys from your Braintree account.', 'paypal-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'sandbox_merchant_id' => array(
                'title' => __('Sandbox Merchant ID', 'paypal-for-woocommerce'),
                'type' => 'password',
                'description' => __('Get your API keys from your Braintree account.', 'paypal-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'public_key' => array(
                'title' => __('Live Public Key', 'paypal-for-woocommerce'),
                'type' => 'password',
                'description' => __('Get your API keys from your Braintree account.', 'paypal-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'private_key' => array(
                'title' => __('Live Private Key', 'paypal-for-woocommerce'),
                'type' => 'password',
                'description' => __('Get your API keys from your Braintree account.', 'paypal-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array('autocomplete' => 'new-password'),
            ),
            'merchant_id' => array(
                'title' => __('Live Merchant ID', 'paypal-for-woocommerce'),
                'type' => 'password',
                'description' => __('Get your API keys from your Braintree account.', 'paypal-for-woocommerce'),
                'default' => '',
                'desc_tip' => true
            ),
            'payment_action' => array(
                'title' => __('Payment Action', 'paypal-for-woocommerce'),
                'label' => __('Whether to process as a Sale or Authorization.', 'paypal-for-woocommerce'),
                'description' => __('Sale will capture the funds immediately when the order is placed.  Authorization will verify and store payment details.'),
                'type' => 'select',
                'css' => 'max-width:150px;',
                'class' => 'wc-enhanced-select',
                'options' => array(
                    'Sale' => 'Sale',
                    'Authorization' => 'Authorization',
                ),
                'default' => 'Sale'
            ),
            'enable_tokenized_payments' => array(
                'title' => __('Enable Tokenized Payments', 'paypal-for-woocommerce'),
                'label' => __('Enable Tokenized Payments', 'paypal-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Allow buyers to securely save payment details to their account for quick checkout / auto-ship orders in the future.', 'paypal-for-woocommerce'),
                'default' => 'no',
                'class' => 'enable_tokenized_payments'
            ),
            'softdescriptor' => array(
                'title' => __('Credit Card Statement Name', 'paypal-for-woocommerce'),
                'type' => 'text',
                'description' => __('The value entered here will be displayed on the buyer\'s credit card statement. Company name/DBA section must be either 3, 7 or 12 characters and the product descriptor can be up to 18, 14, or 9 characters respectively (with an * in between for a total descriptor name of 22 characters).', 'paypal-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ),
            'enable_apple_pay' => array(
                'title' => __('Enable Apple Pay', 'paypal-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Apple Pay', 'paypal-for-woocommerce'),
                'default' => 'no',
                'description' => ''
            ),
            'enable_google_pay' => array(
                'title' => __('Enable Google Pay', 'paypal-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Google Pay', 'paypal-for-woocommerce'),
                'default' => 'no',
                'description' => ''
            ),
            'merchant_id_google_pay' => array(
                'title' => __('Google Pay Merchant ID', 'paypal-for-woocommerce'),
                'type' => 'text',
                'description' => __('Enter your Google Pay Merchant ID provided by Google.( optional for sandbox mode )', 'paypal-for-woocommerce'),
                'default' => '',
                'desc_tip' => true
            )
        );
    }

    public function process_admin_options()
    {
        parent::process_admin_options();
    }

    public function payment_fields()
    {
        echo '<div id="custom-payment-fields">
            <label>Custom Field <span class="required">*</span></label>
            <input type="text" name="custom_field" autocomplete="off">
        </div>';
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

        if ($this->enabled !== 'yes') {
            error_log('PayKKa Gateway is NOT available: Disabled in settings.');
            return true;
        }

        // 额外调试
        if (!is_checkout()) {
            error_log('PayKKa Gateway is NOT available: Not on checkout page.');
            return true;
        }

        $test = $this->enabled === 'yes';
        error_log('Payment gateway is available: ' . $this->enabled . ' :bool: ' . $test);
        return true;
    }

    public function receipt_page($order_id)
    {

    }


    public function process_payment($order_id)
    {
        // 真实代码
        $order = wc_get_order($order_id);

        // $order->update_status('on-hold', __('Awaiting custom payment', 'woocommerce-custom-payment-gateway'));

        // 标记订单为已支付
        // $order->payment_complete();

        // 减少库存
        // wc_reduce_stock_levels($order_id);

        // 清空购物车
        // WC()->cart->empty_cart();

        // 返回成功和重定向链接
        $url_code = $this->do_paykka_payment_2(100, $order_id);
        // print "请求url" . $url_code . "";

        error_log("session url " . $url_code);

        return array(
            'result' => 'success',
            'redirect' => $url_code,
        );
    }

    public function handle_payment_callback()
    {
        echo '<script>console.log("回调准备' . $_REQUEST['order_id'] . '")</script>';
        // 获取收银台返回的支付结果参数
        // $payment_result = $_GET; // 假设使用 GET 请求，实际根据情况调整

        $order_id = $_REQUEST['order_id'];
        $order = wc_get_order($order_id);

        $order->payment_complete();
        wc_reduce_stock_levels($order_id);
        $order->add_order_note(__('Payment completed via custom payment gateway.', 'woocommerce'));
        // 跳转到订单完成页面
        wp_redirect($this->get_return_url($order));
        exit;
    }

    private function do_paykka_payment($cart_total, $order_id)
    {

        $timestamp = time();
        $cart_total = $cart_total * 100;
        // 待优化--TODO
        $now = new DateTime('now', new DateTimeZone('UTC'));
        // 转换为香港时间
        $now->setTimezone(new DateTimeZone('Asia/Hong_Kong'));
        // 使用 DateInterval 对象来添加 5 分钟
        $now->add(new DateInterval('PT5M'));
        $expire_time = $now->format('Y-m-d H:i:s');
        $callback_url = add_query_arg('wc-api', 'WC_Gateway_Custom_Payment_callback', home_url('/')) . "&order_id=" . $order_id;

        $http_body = '{
            "version": "v1.0",
            "merchant_id": "18145872784048",
            "payment_type": "PURCHASE",
            "trans_id": "m' . $timestamp . '",
            "timestamp": ' . $timestamp . ',
            "currency": "EUR",
            "amount": "' . $cart_total . '",
            "notify_url": "https://pub-dev.eu.paykka.com/prefix/callback?id=m11785643765251",
            "return_url": "' . $callback_url . '",
            "expire_time": "' . $expire_time . '",
            "session_mode": "HOST",
            "display_merchant_name": "Paykka Test Merchant 38",
            "display_locale": "es-ES",
            "theme_id": "TQZ",
            "goods": [
                {
                    "id": "6903743507161",
                    "name": "Client poverty mountain porch correct sight interested western adapt almost",
                    "description": "Hide criteria whole much soft chapter duty boot everybody regularly someone film officer gaze mount glove stage manner crisis promise edge commission entrance recovery widespread dead shrug hungry fourth base huge tendency drug history rare inside matter physical the heaven significance enable chief corporate settle station yes easily son absolute",
                    "category": "KEEEOFLZLQ",
                    "brand": "诺君安",
                    "link": "http://vkcqntwhqyr4l.cc/6176333747692/UAgqdRtfWUxU5rm60vH7.shop",
                    "price": 9068,
                    "quantity": 9759,
                    "delivery_date": "1989-01-28T01:55:04+08:00",
                    "picture_url": "http://rl1vnqfy0ts8.org/3946280769095/XE91M0jYlt9Li3V7l7ISVLANAL.jpg"
                },
                {
                    "id": "6903700259065",
                    "name": "Teen peak swear buyer sight greatest arrangement cover off constant",
                    "description": "New publicly tree future hotel addition even frame dangerous command reading eager chain chief assessment religious connection ultimate slice intention again present ceiling who vote west meanwhile area legal Bible buck egg environment wall educational emerge identity recognize increasingly time nature golden sugar resist confirm pie impression extend valley anniversary",
                    "category": "ZAZRZI",
                    "brand": "蓝贝股份",
                    "link": "https://jfd0hcre.cn/7399179281482/Z9z5QfaM1zFlAxwYAaPMfyF9mwvi.shop",
                    "price": 6667,
                    "quantity": 4220,
                    "delivery_date": "2020-07-13T23:20:06+08:00",
                    "picture_url": "http://v1f.tv/5522950716815/H8Om1xdsUD8OwfIW8bxFwDoOKmu.jpg"
                }
            ],
            "bill": {
                "country": "US",
                "email": "",
                "state": "",
                "city": "",
                "address_line1": "",
                "postal_code": "",
                "first_name": "",
                "last_name": "",
                "area_code": "",
                "phone_number": ""
            },
            "shipping": {
                "first_name": "Kerrie",
                "middle_name": "Donald",
                "last_name": "Schranz",
                "address_line1": "广西壮族自治区南宁市邕宁区联谷北路981号达富名都10栋4单元0503房",
                "address_line2": "湖南省长沙市雨花区贤云中路999号达富御院10栋4单元0301房",
                "country": "ML",
                "state": "内蒙古自治区",
                "city": "赤峰市",
                "postal_code": "150404",
                "email": "ruq3s6we@outlook.com",
                "area_code": "0315",
                "phone_number": "64663854"
            },
            "customer": {
                "id": "NMQKZI",
                "registration_time": "2084-02-23T12:10:43+08:00",
                "past_transactions": 9747,
                "area_code": "0391",
                "phone_number": "88355298",
                "date_of_birth": "PQLCVEYZEC",
                "gender": "LFFWTZ",
                "first_shopping_time": "2024-02-10T01:15:22+08:00",
                "last_shopping_time": "1986-07-19T09:56:54+08:00",
                "level": "VVVVVVVIP",
                "email": "jl@zoho.com",
                "pay_ip": "103.54.8.194",
                "order_ip": "ae59:6016:988a:c23c:1ffc:38a4:aa92:73a0"
            },
            "payment": {
                "store_payment_method": true,
                "token_usage": "CARD_ON_FILE",
                "shopper_reference": "f4911bc8b17106a08f2f7a89a9fc4d11",
                "token": "",
                "card_no": "4242424242424242"
            },
            "authentication": {
                "challenge_indicator": "",
                "authentication_only": false
            }
        }';

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://pub-dev.eu.paykka.com/apis/session',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false, // 禁用 SSL 证书验证
            CURLOPT_SSL_VERIFYHOST => false, // 禁用主机名验证
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $http_body,
            CURLOPT_HTTPHEADER => array(
                'signature: xxxx',
                'type: RSA256',
                'Content-Type: application/json'
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);

        if (curl_errno($curl)) {
            echo 'Error:' . curl_error($curl);
        } else {
            print $response;
        }
        $data = json_decode($response, true);

        // echo '<script>console.log("回调准备' . $data . '")</script>';
        return $data['data']['session_url'];
    }


    private function do_paykka_payment_2($cart_total, $order_id)
    {

        $timestamp = time();
        $cart_total = $cart_total * 100;
        // 待优化--TODO
        $now = new DateTime('now', new DateTimeZone('UTC'));
        // 转换为香港时间
        $now->setTimezone(new DateTimeZone('Asia/Hong_Kong'));
        // 使用 DateInterval 对象来添加 5 分钟
        $now->add(new DateInterval('PT5M'));
        $expire_time = $now->format('Y-m-d H:i:s');
        $callback_url = add_query_arg('wc-api', 'WC_Gateway_Custom_Payment_callback', home_url('/')) . "&order_id=" . $order_id;

        $http_body = '{
            "version": "v1.0",
            "merchant_id": "18145872784048",
            "payment_type": "PURCHASE",
            "trans_id": "m' . $timestamp . '",
            "timestamp": ' . $timestamp . ',
            "currency": "EUR",
            "amount": "' . $cart_total . '",
            "notify_url": "https://pub-dev.eu.paykka.com/prefix/callback?id=m11785643765251",
            "return_url": "' . $callback_url . '",
            "expire_time": "' . $expire_time . '",
            "session_mode": "HOST",
            "display_merchant_name": "Paykka Test Merchant 38",
            "display_locale": "es-ES",
            "theme_id": "TQZ",
            "goods": [
                {
                    "id": "6903743507161",
                    "name": "Client poverty mountain porch correct sight interested western adapt almost",
                    "description": "Hide criteria whole much soft chapter duty boot everybody regularly someone film officer gaze mount glove stage manner crisis promise edge commission entrance recovery widespread dead shrug hungry fourth base huge tendency drug history rare inside matter physical the heaven significance enable chief corporate settle station yes easily son absolute",
                    "category": "KEEEOFLZLQ",
                    "brand": "诺君安",
                    "link": "http://vkcqntwhqyr4l.cc/6176333747692/UAgqdRtfWUxU5rm60vH7.shop",
                    "price": 9068,
                    "quantity": 9759,
                    "delivery_date": "1989-01-28T01:55:04+08:00",
                    "picture_url": "http://rl1vnqfy0ts8.org/3946280769095/XE91M0jYlt9Li3V7l7ISVLANAL.jpg"
                },
                {
                    "id": "6903700259065",
                    "name": "Teen peak swear buyer sight greatest arrangement cover off constant",
                    "description": "New publicly tree future hotel addition even frame dangerous command reading eager chain chief assessment religious connection ultimate slice intention again present ceiling who vote west meanwhile area legal Bible buck egg environment wall educational emerge identity recognize increasingly time nature golden sugar resist confirm pie impression extend valley anniversary",
                    "category": "ZAZRZI",
                    "brand": "蓝贝股份",
                    "link": "https://jfd0hcre.cn/7399179281482/Z9z5QfaM1zFlAxwYAaPMfyF9mwvi.shop",
                    "price": 6667,
                    "quantity": 4220,
                    "delivery_date": "2020-07-13T23:20:06+08:00",
                    "picture_url": "http://v1f.tv/5522950716815/H8Om1xdsUD8OwfIW8bxFwDoOKmu.jpg"
                }
            ],
            "bill": {
                "country": "US",
                "email": "",
                "state": "",
                "city": "",
                "address_line1": "",
                "postal_code": "",
                "first_name": "",
                "last_name": "",
                "area_code": "",
                "phone_number": ""
            },
            "shipping": {
                "first_name": "Kerrie",
                "middle_name": "Donald",
                "last_name": "Schranz",
                "address_line1": "广西壮族自治区南宁市邕宁区联谷北路981号达富名都10栋4单元0503房",
                "address_line2": "湖南省长沙市雨花区贤云中路999号达富御院10栋4单元0301房",
                "country": "ML",
                "state": "内蒙古自治区",
                "city": "赤峰市",
                "postal_code": "150404",
                "email": "ruq3s6we@outlook.com",
                "area_code": "0315",
                "phone_number": "64663854"
            },
            "customer": {
                "id": "NMQKZI",
                "registration_time": "2084-02-23T12:10:43+08:00",
                "past_transactions": 9747,
                "area_code": "0391",
                "phone_number": "88355298",
                "date_of_birth": "PQLCVEYZEC",
                "gender": "LFFWTZ",
                "first_shopping_time": "2024-02-10T01:15:22+08:00",
                "last_shopping_time": "1986-07-19T09:56:54+08:00",
                "level": "VVVVVVVIP",
                "email": "jl@zoho.com",
                "pay_ip": "103.54.8.194",
                "order_ip": "ae59:6016:988a:c23c:1ffc:38a4:aa92:73a0"
            },
            "payment": {
                "store_payment_method": true,
                "token_usage": "CARD_ON_FILE",
                "shopper_reference": "f4911bc8b17106a08f2f7a89a9fc4d11",
                "token": "",
                "card_no": "4242424242424242"
            },
            "authentication": {
                "challenge_indicator": "",
                "authentication_only": false
            }
        }';

        // 定义请求头
        $headers = array(
            'Content-Type' => 'application/json', // 设置内容类型为 JSON
            'signature' => 'BearerYOUR_ACCESS_TOKEN', // 添加认证头
        );

        $response = wp_remote_post('https://pub-dev.eu.paykka.com/apis/session', array(
            'headers' => $headers,
            'body' => $http_body,
        ));

        // 检查请求是否出错
        if (is_wp_error($response)) {
            wc_add_notice('Payment error: ' . $response->get_error_message(), 'error');
            return;
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        // echo '<script>console.log("回调准备' . $data . '")</script>';
        return $response_data['data']['session_url'];
    }
}