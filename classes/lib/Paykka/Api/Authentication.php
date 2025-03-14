<?php

namespace lib\Paykka\Api;

class Authentication {
    public $challenge_indicator;
    public $authentication_only;

    public function __get($property) {
        return $this->$property ?? null;
    }

    public function __set($property, $value) {
        $this->$property = $value;
    }
    
}