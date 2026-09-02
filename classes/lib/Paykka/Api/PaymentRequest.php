<?php

namespace lib\Paykka\Api;
class PaymentRequest {
    public $version;
    public $merchant_id;
    public $payment_type;
    public $trans_id;
    public $timestamp;
    public $currency;
    public $amount;
    public $notify_url;
    public $return_url;
    public $expire_time;
    public $session_mode;
    /** 交易失败或取消跳转地址 (v3) */
    public $cancel_url;
    public $display_merchant_name;
    public $display_locale;
    public $theme_id;
    /** @var array|null 允许展示的支付方式，如 APPLE_PAY / GOOGLE_PAY / BANKCARD */
    public $allowed_payment_methods;
    public $goods = [];
    public $bill;
    public $shipping;
    public $customer;
    public $payment;
    public $browser;
    public $authentication;
    
    public $capture_method;
    
    public function __get($property) {
        return $this->$property ?? null;
    }

    public function __set($property, $value) {
        $this->$property = $value;
    }

    public function toJson() {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private function toArray() {
        $arr = array(
            'merchant_id' => $this->merchant_id,
            'payment_type' => $this->payment_type,
            'trans_id' => $this->trans_id,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'notify_url' => $this->notify_url,
            'return_url' => $this->return_url,
            'cancel_url' => $this->cancel_url,
            'expire_time' => $this->expire_time,
            'session_mode' => $this->session_mode,
            'display_merchant_name' => $this->display_merchant_name,
            'display_locale' => $this->display_locale,
            'theme_id' => $this->theme_id,
            'allowed_payment_methods' => $this->allowed_payment_methods,
            'capture_method' => $this->capture_method,
            'goods' => array_map(fn($good) => $this->objectToArray($good), $this->goods),
            'bill' => $this->objectToArray($this->bill),
            'browser' => $this->objectToArray($this->browser),
            'shipping' => $this->objectToArray($this->shipping),
            'customer' => $this->objectToArray($this->customer),
            'payment' => $this->objectToArray($this->payment),
            'authentication' => $this->objectToArray($this->authentication),
        );
        return array_filter($arr, function ($v) {
            return $v !== null && $v !== '';
        });
    }

    private function objectToArray($object) {
        if (!$object) return null;
        if (is_array($object)) {
            return array_map(fn($item) => $this->objectToArray($item), $object);
        }
        return get_object_vars($object);
    }

}
