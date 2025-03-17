<?php
namespace lib\Paykka\Request;

use lib\Paykka\Api\Bill;
use lib\Paykka\Api\Shipping;
use lib\Paykka\Api\Goods;
use lib\Paykka\Api\PayCustomer;
use lib\Paykka\Api\PaymentInfo;
use lib\Paykka\Api\PaymentRequest;
use lib\Paykka\Request\PaykkaWebHookHandler;


require_once FENGQIAO_PAYKKA_URL . '/classes/lib/Paykka/Api/Bill.php';
require_once FENGQIAO_PAYKKA_URL . '/classes/lib/Paykka/Api/Shipping.php';
require_once FENGQIAO_PAYKKA_URL . '/classes/lib/Paykka/Api/Goods.php';
require_once FENGQIAO_PAYKKA_URL . '/classes/lib/Paykka/Api/PayCustomer.php';
require_once FENGQIAO_PAYKKA_URL . '/classes/lib/Paykka/Api/PaymentInfo.php';
require_once FENGQIAO_PAYKKA_URL . '/classes/lib/Paykka/Api/PaymentRequest.php';
require_once FENGQIAO_PAYKKA_URL . '/classes/lib/Paykka/Request/PaykkaWebHookHandler.php';


class PaykkaRequestHandler
{


    public function build($order, $PAYKKA_MERCHANT_ID, $PAYKKA_API_KEY)
    {

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        // 转换为香港时间
        $now->setTimezone(new \DateTimeZone('Asia/Hong_Kong'));
        // 使用 DateInterval 对象来添加 5 分钟
        $now->add(new \DateInterval('PT5M'));
        $expire_time = $now->format('Y-m-d H:i:s');
        $timestamp = round(microtime(true) * 1000);
        $callback_url = add_query_arg('wc-api', 'WC_Gateway_Custom_Payment_callback', home_url('/')) . "&order_id=" . $order->get_id();

        $notify_url = rest_url(PaykkaWebHookHandler::$WEB_HOOK_URL);

        $paymentRequest = new PaymentRequest();
        $paymentRequest->version = 'v1.0';
        $paymentRequest->__set('merchant_id', $PAYKKA_MERCHANT_ID);
        $paymentRequest->__set('payment_type', 'PURCHASE');
        $paymentRequest->__set('trans_id', $order->get_id());
        $paymentRequest->__set('timestamp', $timestamp);
        $paymentRequest->__set('currency', $order->get_currency());
        $paymentRequest->__set('amount', $order->get_total() * 100);
        $paymentRequest->__set('notify_url', $notify_url);
        $paymentRequest->__set('return_url', $callback_url);
        $paymentRequest->__set('expire_time', $expire_time);
        $paymentRequest->__set('session_mode', 'HOST');

        $paymentRequest->bill = $this->buildBill($order);
        $paymentRequest->shipping = $this->buildShipping($order);
        $paymentRequest->goods = $this->buildGoodsItems($order);
        $paymentRequest->customer = $this->buildCustomer($order);

        $http_body = $paymentRequest->toJson();
        // error_log(message: "http_body: \n". $http_body);

        //sign
        $signStr = $this->paykkaSign($PAYKKA_MERCHANT_ID, $timestamp, $http_body, $PAYKKA_API_KEY);

        error_log("signStr: \n" .$signStr);
        // 定义请求头
        $headers = array(
            'Content-Type' => 'application/json', // 设置内容类型为 JSON
            'signature' => $signStr,
            'type' => 'RSA256' // 添加认证头
        );

        $response = wp_remote_post('https://pub-dev.eu.paykka.com/apis/session', array(
        // $response = wp_remote_post('http://localhost:8080/apis/session', array(
            'headers' => $headers,
            'body' => $http_body,
        ));

        error_log("response: \n");

        // 检查请求是否出错
        if (is_wp_error($response)) {
            wc_add_notice('Payment error: ' . $response->get_error_message(), 'error');
            return;
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        error_log("response_body: \n" . $response_body);

        // echo '<script>console.log("回调准备' . $data . '")</script>';
        return $response_data['data']['session_url'];
    }

    private function buildBill($order)
    {
        $bill = new Bill();
        $bill->first_name = $order->get_billing_first_name();
        $bill->last_name = $order->get_billing_last_name();
        $bill->address_line1 = $order->get_billing_address_1();
        $bill->address_line2 = $order->get_billing_address_2();

        $bill->country = $order->get_billing_country();
        $bill->state = $order->get_billing_state();
        $bill->city = $order->get_billing_city();
        $bill->postal_code = $order->get_billing_postcode();

        $bill->email = $order->get_billing_email();
        // $bill->area_code = $order->get_billing_phone();
        // $bill->phone_number = $order->get_billing_phone();
        $bill_phone_number = $order->get_billing_phone();
        if (!empty($bill_phone_number)) {
            $phone_parts = explode(' ', $bill_phone_number, 2);
            $bill->area_code  = $phone_parts[0] ?? '';
            $bill-> phone_number = $phone_parts[1] ?? $bill_phone_number;
        }
        // $bill->descriptor = $order->getdis();
        return $bill;
    }


    private function buildShipping($order)
    {
        $ship = new Shipping();
        $ship->first_name = $order->get_shipping_first_name();
        $ship->last_name = $order->get_shipping_last_name();
        $ship->address_line1 = $order->get_shipping_address_1();
        $ship->address_line2 = $order->get_shipping_address_2();

        $ship->country = $order->get_shipping_country();
        $ship->state = $order->get_shipping_state();
        $ship->city = $order->get_shipping_city();
        $ship->postal_code = $order->get_shipping_postcode();

        // $ship->email = $order->get_billing_email();
        // $bill->area_code = $order->get_billing_phone();
        $ship_phone_number = $order->get_shipping_phone();
        if ($ship_phone_number != null) {
            $phone_parts = explode(' ', $ship_phone_number, 2);
            $ship->area_code  = $phone_parts[0] ?? '';
            $ship-> phone_number = $phone_parts[1] ?? $ship_phone_number;
        }
        // $ship-> phone_number = $order->get_shipping_phone();
        // $ship-> shipping_email = $order->get_shipping_email();
        // $bill->descriptor = $order->getdis();
        return $ship;
    }

    private function buildCustomer($order)
    {
        $user = wp_get_current_user();

        $customer = new PayCustomer();
        $customer->id = $order->get_user_id();
        $customer->registration_time  = $user->user_registered;  
        $customer->email = $user->user_email; 
        $customer->order_ip = $order->get_customer_ip_address();
        return $customer;
    }

    private function buildGoodsItems($order)
    {
        $goods_items = [];
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $goods = new Goods();

            $goods->id = $product->get_id();
            $goods->name = $product->get_name();
            $goods->description = $product->get_description();
            $goods->category =   wp_get_post_terms($goods->id, 'product_cat');
            // $goods-> brand = $item->get_name();
            $goods->link = $product-> get_permalink(); 
            $goods->price = $product->get_price();
            $goods->quantity = $item->get_quantity();
            $goods->delivery_date = $product->get_meta('_delivery_date');
            $goods->picture_url = get_post_meta($goods->id, '_picture_url', true);

            $goods_items[] = $goods;
        }
        return $goods_items;
    }


    /**
     * 下单签名
     */
    public function paykkaSign($merchantId, $timestamp, $requestBody, $PAYKKA_API_KEY)
    {
        // 签名格式
        $FORMAT = "merchantId=%s&timestamp=%s&requestBody=%s";

        // 生成签名字符串
        $content = sprintf($FORMAT, $merchantId, $timestamp, $requestBody);

        // 加载私钥
        // $PAYKKA_API_KEY = $this -> privateKeyStr;
        // error_log("response: \n" .$PAYKKA_API_KEY);
        $privateKey = openssl_pkey_get_private($PAYKKA_API_KEY);
        if (!$privateKey) {
            while ($error = openssl_error_string()) {
                error_log($error);
            }
            die("无法加载私钥" . $PAYKKA_API_KEY);
        }

        $signature = null;

        // 3. 使用 SHA256withRSA 签名
        openssl_sign($content, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if ($signature === false) {
            die("签名失败");
        }

        // 释放私钥资源
        openssl_free_key($privateKey);

        // Base64 编码签名
        $base64Signature = base64_encode($signature);

        // URL 编码签名
        $sign = urlencode($base64Signature);

        return $sign;
    }

    public $privateKeyStr = "-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQCtqnN9oApv8mwf
RdhT6Ka35msbuq1YsWcHoWyAPs1p1QSz3CA+ZdwrAMFoauZuoE2nnZjG13Hd1CmJ
fBNLGBQxKFfYwGzJBPBb3AoU5ejCk8dV5adPcWvfZMdEm3gcDn/dQ0PrZi4sKzZ3/
ibxQMg8LJST3E3e/HnetoC0lGMhBVnmfwmVbBw5dEmOZJ/5rCJ9776Uljj6YpVva
GfSUwCcbrwdzMuJXxSxnWWsuAGbGVyP+gwwWe8pGF5v2V+cIAfIVDDeXoq/jggc8
1uul6unqZFAa2dSYvTWMla43CaOEqD158OsHG9HPGCVtjJsm+0VmfkzFTBzrYL+q
Llbp/bPAgMBAAECggEBAJXo2SDMEbZo0SR9qitkXOXKJRMepZw2JvXTRlG95JtCon
iPv9WdH9yPHmUAQkGkZuQVile6ijQufFyNminscyGr7YjRMhakCMeCvcEkZTPxVN
S1FSPiiHeiCtESUzAE5CMfeXWuEpVWCAK0hPEkNrSa1vZ76UxfLOQvLhKzNI6/Hu
47IW+YvqoK38rJ2NIQOdECuKa6CaTRuPxndkJXTF+iZiXDc9wYm6PmtVSfUKL2TD
GpWXtZnO4n7YuZqgECkcdgnY1hHiqKPmnhtsjKGFf8rw1SfH8tXqZHOY1rGXOlVq
7FD89sUYZsBd15TlEURp/XrvsKaKW1fFc02y2mMIECgYEA4YnpwBciqWYadWibEy
Aj431tBdCnega1C1GajE+36HBcikBKpt7rYuezrYIyNrZKTUTcjvCdyxypJJDNQk
5fDcF8LxKkXdkx0x7S4IbvG4Xa8qFBpcIompA6Kt6z9wKYYvQKbCKUU/pt1L6JiG
fjP5EuI/p/X1z8uaUz+9DS0d8CgYEAxR8DTi/rJq5PRIKQkhAsgvus3vzdmk2Ig+F
PI74PXKJiNeIGvhcp04IdTRpNJj0E2/WsHAta8CeQUNNEazvZePok7ms83YwgLis
I0f3JiZ/bS2c03J3Zc1zXI1eFNgMzrLYhxP2hOe6s7oT7fDHH3aD0rjOQpC4RM0p
awUJ+2RECgYB89sUlQaxa38/ZLdR+jFhWO7CkgC/LVNwLIXPYOnNTvq4HjAfQ3cL
eUjMj9/eKiQYyOe1a5ccIOyEcuX6BNptEK+h6zIF13lnU+EcvUJQ7U7c0qFSPWzzU
JwWTq0Fbo3x7l2wO7jnxLdic/9WEVst69R3zoV/hnswIsJhU9idZUQKBgQCIYceo
rfC1R36ieO9Lj5MsYLKfaTZtTt132UgnA5WfUt4+R47AsEgJBYn+UYc1QJx/Dv+w
O48Ef2sS8MjypGr3j6JDrsBizFNrfezRVRS+enKAPfzN8wyDC6Xx1tjcoOR8x1qf
75c//Ml7EVjp+Ys95OHFMPoPDaxq3zPhaH9Y8QKBgGT7cQcG7+WeiYy9+FtVHGyh
CZoxF8Sdq6RmLeObVXC1o2XcpPGT9OJOJR/HvWkzB5IU/dnhPxI394SLvXExGR0J
syf1fUNx3dH3QG6+leWiaqPkta2a2KbtdkhM7Omei58qhJ1EEB7oJWc/KNdGFXPq
E+WaihZES15+4j26Qa+o
-----END PRIVATE KEY-----";

}