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
    public $display_merchant_name;
    public $display_locale;
    public $theme_id;
    public $goods = [];
    public $bill;
    public $shipping;
    public $customer;
    public $payment;
    public $authentication;

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
        return [
            'version' => $this->version,
            'merchant_id' => $this->merchant_id,
            'payment_type' => $this->payment_type,
            'trans_id' => $this->trans_id,
            'timestamp' => $this->timestamp,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'notify_url' => $this->notify_url,
            'return_url' => $this->return_url,
            'expire_time' => $this->expire_time,
            'session_mode' => $this->session_mode,
            'display_merchant_name' => $this->display_merchant_name,
            'display_locale' => $this->display_locale,
            'theme_id' => $this->theme_id,
            'goods' => array_map(fn($good) => $this->objectToArray($good), $this->goods),
            'bill' => $this->objectToArray($this->bill),
            'shipping' => $this->objectToArray($this->shipping),
            'customer' => $this->objectToArray($this->customer),
            'payment' => $this->objectToArray($this->payment),
            'authentication' => $this->objectToArray($this->authentication),
        ];
    }

    private function objectToArray($object) {
        if (!$object) return null;
        if (is_array($object)) {
            return array_map(fn($item) => $this->objectToArray($item), $object);
        }
        return get_object_vars($object);
    }

}
