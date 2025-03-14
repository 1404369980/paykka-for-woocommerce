<?php

namespace lib\Paykka\Api;

class Goods {
    public $id;
    public $name;
    public $description;
    public $category;
    public $brand;
    public $link;
    public $price;
    public $quantity;
    public $delivery_date;
    public $picture_url;

    public function __get($property) {
        return $this->$property ?? null;
    }

    public function __set($property, $value) {
        $this->$property = $value;
    }
    
}
