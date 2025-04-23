<?php

if (!defined('ABSPATH')) {
    exit;
}
// if (!class_exists('WC_Settings_Page')) return;

function getPaykkaSettings(){
    $paykka_sandbox_flag =  get_option('paykka_sandbox_flag');

    if($paykka_sandbox_flag == 'yes'){
        return [
            'paykka_client_key' => get_option('paykka_sandbox_client_key'),
            'paykka_public_key' => get_option('paykka_sandbox_public_key'),
            'paykka_private_key' => get_option('paykka_sandbox_private_key'),
            'paykka_merchant_id' => get_option('paykka_sandbox_merchant_id'),
            'paykka_client_key' => get_option('paykka_sandbox_client_key'),
        ];
    }else{
        return [
            'paykka_client_key' => get_option('paykka_client_key'),
            'paykka_public_key' => get_option('paykka_public_key'),
            'paykka_private_key' => get_option('paykka_private_key'),
            'paykka_merchant_id' => get_option('paykka_merchant_id'),
            'paykka_client_key' => get_option('paykka_client_key'),
        ];
    }
}