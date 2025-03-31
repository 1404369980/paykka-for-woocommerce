<?php

namespace lib\Paykka\Api;
class PaymentInfo {

    public $payment_method;

    public $encrypted_card_no;

    public $encrypted_exp_year;

    public $encrypted_exp_month;

    public $encrypted_cvv;

    public $holder_name;

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
