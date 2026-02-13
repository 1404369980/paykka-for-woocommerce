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
 * 加载 Paykka 配置文件（可选）
 * 优先：wp-content/paykka-config.php（升级插件不覆盖），其次：插件目录 paykka-config.php。
 * 配置文件 return 数组，需包含 'env' => 'production'|'sandbox'，可选 'production'/'sandbox' 下 api_base_url、checkout_base_url。
 *
 * @return array{env?: string, production?: array, sandbox?: array}
 */
function paykka_load_config()
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $config = array();
    $candidates = array();
    if (defined('ABSPATH')) {
        $candidates[] = ABSPATH . 'wp-content/paykka-config.php';
    }
    if (defined('PAYKKA_PLUGIN_PATH')) {
        $candidates[] = PAYKKA_PLUGIN_PATH . 'paykka-config.php';
    }
    foreach ($candidates as $path) {
        if (is_readable($path)) {
            $loaded = include $path;
            if (is_array($loaded)) {
                $config = $loaded;
            }
            break;
        }
    }
    return $config;
}

/**
 * 是否使用沙箱/测试环境（生产 = false，测试 = true）
 * 优先级：配置文件 env > wp-config 常量 PAYKKA_ENV > 后台 Sandbox 勾选。
 *
 * @return bool
 */
function paykka_is_sandbox()
{
    $config = paykka_load_config();
    if (!empty($config['env'])) {
        $env = strtolower((string) $config['env']);
        if ($env === 'production' || $env === 'prod') {
            return false;
        }
        if ($env === 'sandbox' || $env === 'test') {
            return true;
        }
    }
    if (defined('PAYKKA_ENV')) {
        $env = strtolower((string) PAYKKA_ENV);
        if ($env === 'production' || $env === 'prod') {
            return false;
        }
        if ($env === 'sandbox' || $env === 'test') {
            return true;
        }
    }
    return get_option('paykka_sandbox_flag', 'yes') === 'yes';
}

// 生产：亚太 openapi.paykka.com，欧洲 openapi.eu.paykka.com
// 沙箱：openapi-fat 若不存在则使用 pub-fat（Paykka 测试环境常用 pub-fat 子域）
if (!defined('PAYKKA_API_BASE_AP_SANDBOX')) {
    define('PAYKKA_API_BASE_AP_SANDBOX', 'https://pub-fat.paykka.com');
}
if (!defined('PAYKKA_API_BASE_AP_PROD')) {
    define('PAYKKA_API_BASE_AP_PROD', 'https://openapi.paykka.com');
}
if (!defined('PAYKKA_API_BASE_EU_SANDBOX')) {
    define('PAYKKA_API_BASE_EU_SANDBOX', 'https://pub-fat.eu.paykka.com');
}
if (!defined('PAYKKA_API_BASE_EU_PROD')) {
    define('PAYKKA_API_BASE_EU_PROD', 'https://openapi.eu.paykka.com');
}
// 兼容旧常量名（指向欧洲）
if (!defined('PAYKKA_API_BASE_SANDBOX')) {
    define('PAYKKA_API_BASE_SANDBOX', 'https://pub-fat.eu.paykka.com');
}
if (!defined('PAYKKA_API_BASE_PROD')) {
    define('PAYKKA_API_BASE_PROD', 'https://openapi.eu.paykka.com');
}
if (!defined('PAYKKA_CHECKOUT_BASE_SANDBOX')) {
    define('PAYKKA_CHECKOUT_BASE_SANDBOX', 'https://checkout-fat.eu.paykka.com');
}
if (!defined('PAYKKA_CHECKOUT_BASE_PROD')) {
    define('PAYKKA_CHECKOUT_BASE_PROD', 'https://checkout.eu.paykka.com');
}

/**
 * 获取 Paykka API 地区：ap（亚太）| eu（欧洲）
 *
 * @return string 'ap'|'eu'
 */
function paykka_get_api_region()
{
    $region = get_option('paykka_api_region', 'eu');
    return $region === 'ap' ? 'ap' : 'eu';
}

/**
 * 获取 Paykka 后端 API 基地址（根据地区 + 生产/测试 + 配置文件或后台覆盖）
 * 优先级：配置文件当前环境的 api_base_url > 后台「API Base URL」> 按地区+环境的默认 openapi 地址。
 *
 * @return string 不含末尾斜杠的完整基地址，如 https://openapi.eu.paykka.com
 */
function paykka_get_api_base_url()
{
    $sandbox = paykka_is_sandbox();
    $config = paykka_load_config();
    $env_key = $sandbox ? 'sandbox' : 'production';
    if (!empty($config[$env_key]['api_base_url']) && is_string($config[$env_key]['api_base_url'])) {
        return rtrim($config[$env_key]['api_base_url'], '/');
    }
    $custom = get_option('paykka_api_base_url', '');
    if (is_string($custom) && $custom !== '') {
        return rtrim($custom, '/');
    }
    $region = paykka_get_api_region();
    if ($region === 'ap') {
        return $sandbox ? PAYKKA_API_BASE_AP_SANDBOX : PAYKKA_API_BASE_AP_PROD;
    }
    return $sandbox ? PAYKKA_API_BASE_EU_SANDBOX : PAYKKA_API_BASE_EU_PROD;
}

/**
 * 获取 Paykka 收银台前端基地址（JS/CSS 与 setApiUrl/setCDNUrl 用）
 * 优先级：配置文件当前环境的 checkout_base_url > 后台「Checkout Base URL」> 默认生产/测试地址。
 *
 * @return string 不含末尾斜杠的完整基地址，如 https://checkout.eu.paykka.com
 */
function paykka_get_checkout_base_url()
{
    $sandbox = paykka_is_sandbox();
    $config = paykka_load_config();
    $env_key = $sandbox ? 'sandbox' : 'production';
    if (!empty($config[$env_key]['checkout_base_url']) && is_string($config[$env_key]['checkout_base_url'])) {
        return rtrim($config[$env_key]['checkout_base_url'], '/');
    }
    $custom = get_option('paykka_checkout_base_url', '');
    if (is_string($custom) && $custom !== '') {
        return rtrim($custom, '/');
    }
    return $sandbox ? PAYKKA_CHECKOUT_BASE_SANDBOX : PAYKKA_CHECKOUT_BASE_PROD;
}

/**
 * 获取 Paykka 配置（根据沙箱开关返回对应环境的 key/merchant_id）
 *
 * @return array{paykka_client_key: string, paykka_public_key: string, paykka_private_key: string, paykka_merchant_id: string}
 */
function getPaykkaSettings()
{
    $sandbox = paykka_is_sandbox();
    $prefix = $sandbox ? 'paykka_sandbox_' : 'paykka_';
    $client_key_id = $sandbox ? 'paykka_sandbox_client_key' : 'paykka_client_key';

    return array(
        'paykka_client_key'  => get_option($client_key_id, ''),
        'paykka_public_key'  => get_option($prefix . 'public_key', ''),
        'paykka_private_key' => get_option($prefix . 'private_key', ''),
        'paykka_merchant_id' => get_option($prefix . 'merchant_id', ''),
    );
}