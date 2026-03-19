<?php
/**
 * Paykka 环境与地址配置示例
 *
 * 使用方式：
 * 1. 复制本文件为 paykka-config.php（可放在插件目录或 wp-content 下，推荐 wp-content 以免升级被覆盖）
 * 2. 设置下方 'env' 为 'production' 或 'sandbox' 以选择当前环境
 * 3. 如需自定义某环境的 API/收银台地址，在 production 或 sandbox 中填写
 *
 * 环境选择优先级：本配置文件 env > wp-config.php 中 define('PAYKKA_ENV','production') > 后台「Sandbox」勾选
 */

return array(
    // 当前环境：production（生产）| sandbox（测试）
    'env' => 'sandbox',

    // 生产环境地址（仅当 env=production 且未在后台填写时，此处生效）
    'production' => array(
        'api_base_url'      => 'https://openapi.eu.paykka.com',      // 欧洲；香港可填 https://openapi.aq.paykka.com
        'checkout_base_url' => 'https://checkout.eu.paykka.com',
    ),

    // 测试/沙箱环境地址（仅当 env=sandbox 且未在后台填写时，此处生效）
    'sandbox' => array(
        'api_base_url'      => 'https://openapi-sandbox.paykka.com',
        'checkout_base_url' => 'https://checkout-fat.eu.paykka.com',
    ),
);
