<?php

namespace lib\Paykka\Api;
class PaymentInfo {
    public $store_payment_method;
    public $token_usage;
    public $shopper_reference;
    public $token;
    public $card_no;

    public function __get($property) {
        return $this->$property ?? null;
    }

    public function __set($property, $value) {
        $this->$property = $value;
    }
    
}
