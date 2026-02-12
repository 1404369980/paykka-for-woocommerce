<?php

if (!defined('ABSPATH')) {
    exit;
}
// if (!class_exists('WC_Settings_Page')) return;

/**
 * 是否开启 Paykka Debug 模式（开发时用于输出详细 error_log）。
 * 为 true 当：后台「开启 Debug 模式」勾选，或 wp-config 中 WP_DEBUG 为 true。
 *
 * @return bool
 */
function paykka_is_debug()
{
    if (function_exists('get_option')) {
        // 未保存过选项时默认视为开启，便于开发
        if (get_option('paykka_debug_mode', 'yes') === 'yes') {
            return true;
        }
    }
    return defined('WP_DEBUG') && WP_DEBUG;
}

/**
 * 是否记录 Paykka 接口请求/响应日志。
 * 为 true 当：Debug 模式、或勾选「记录请求结果日志」、或 WP_DEBUG_LOG。
 *
 * @return bool
 */
function paykka_is_log_enabled()
{
    if (paykka_is_debug()) {
        return true;
    }
    if (function_exists('get_option') && get_option('paykka_log_request_result', 'yes') === 'yes') {
        return true;
    }
    return defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
}

/**
 * 获取 Paykka 配置（根据沙箱开关返回对应环境的 key/merchant_id）
 *
 * @return array{paykka_client_key: string, paykka_public_key: string, paykka_private_key: string, paykka_merchant_id: string}
 */
function getPaykkaSettings()
{
    $sandbox = get_option('paykka_sandbox_flag') === 'yes';
    $prefix = $sandbox ? 'paykka_sandbox_' : 'paykka_';
    $client_key_id = $sandbox ? 'paykka_sandbox_client_key' : 'paykka_client_key';

    return array(
        'paykka_client_key'  => get_option($client_key_id, ''),
        'paykka_public_key'  => get_option($prefix . 'public_key', ''),
        'paykka_private_key' => get_option($prefix . 'private_key', ''),
        'paykka_merchant_id' => get_option($prefix . 'merchant_id', ''),
    );
}