<?php

namespace lib\Paykka\Api;

class PayCustomer {
    public $id;
    public $registration_time;
    public $past_transactions;
    public $area_code;
    public $phone_number;
    public $date_of_birth;
    public $gender;
    public $first_shopping_time;
    public $last_shopping_time;
    public $level;
    public $email;
    public $pay_ip;
    public $order_ip;

    public function __get($property) {
        return $this->$property ?? null;
    }

    public function __set($property, $value) {
        $this->$property = $value;
    }

}
