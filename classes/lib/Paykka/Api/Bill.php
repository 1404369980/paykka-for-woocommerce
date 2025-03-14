<?php

namespace lib\Paykka\Api;

class Bill {
    public $first_name;
    public $middle_name;
    public $last_name;
    public $address_line1;
    public $address_line2;
    public $country;
    public $state;
    public $city;
    public $postal_code;
    public $email;
    public $area_code;
    public $phone_number;
    public $descriptor;

    public function __get($property) {
        return $this->$property ?? null;
    }

    public function __set($property, $value) {
        $this->$property = $value;
    }
    
}
