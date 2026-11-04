<?php
/**
 * 订单列表 / 订单详情：
 * - Payment Order：PayKKa 网关支付订单号（_paykka_order_id）
 * - Merchant Transaction ID：发送给 PayKKa 的商户交易号 trans_id（_paykka_trans_id）
 * Hosted（paykka）与 Payments（paykka-card）共用。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 注册订单列表列（传统 CPT + HPOS）与订单详情展示。
 */
function paykka_register_order_list_hooks()
{
    add_filter('manage_edit-shop_order_columns', 'paykka_add_order_list_column', 20);
    add_action('manage_shop_order_posts_custom_column', 'paykka_render_order_list_column_cpt', 20, 2);

    add_filter('woocommerce_shop_order_list_table_columns', 'paykka_add_order_list_column', 20);
    add_action('woocommerce_shop_order_list_table_custom_column', 'paykka_render_order_list_column_hpos', 20, 2);

    add_action('woocommerce_admin_order_data_after_billing_address', 'paykka_render_order_admin_payment_ids', 20);
}

/**
 * @param array $columns
 * @return array
 */
function paykka_add_order_list_column($columns)
{
    $new = array();
    foreach ($columns as $key => $label) {
        $new[$key] = $label;
        if ($key === 'order_status') {
            $new['paykka_order_id'] = __('Payment Order', 'paykka-for-woocommerce');
        }
    }
    if (!isset($new['paykka_order_id'])) {
        $new['paykka_order_id'] = __('Payment Order', 'paykka-for-woocommerce');
    }
    return $new;
}

/**
 * @param \WC_Order|false $order
 * @return array{payment_order:string,merchant_trans_id:string}
 */
function paykka_get_payment_ids($order)
{
    $empty = array('payment_order' => '', 'merchant_trans_id' => '');
    if (!$order || !is_a($order, 'WC_Order')) {
        return $empty;
    }
    // _paykka_trans_id = Create Session / 支付请求里发给 PayKKa 的 merchant trans_id
    return array(
        'payment_order'     => trim((string) $order->get_meta('_paykka_order_id', true)),
        'merchant_trans_id' => trim((string) $order->get_meta('_paykka_trans_id', true)),
    );
}

/**
 * @param \WC_Order|false $order
 * @return string
 */
function paykka_get_gateway_order_id_display($order)
{
    $ids = paykka_get_payment_ids($order);
    return $ids['payment_order'] !== '' ? $ids['payment_order'] : '—';
}

/**
 * @param string $column
 * @param int    $post_id
 */
function paykka_render_order_list_column_cpt($column, $post_id)
{
    if ($column !== 'paykka_order_id') {
        return;
    }
    echo esc_html(paykka_get_gateway_order_id_display(wc_get_order($post_id)));
}

/**
 * @param string    $column
 * @param \WC_Order $order
 */
function paykka_render_order_list_column_hpos($column, $order)
{
    if ($column !== 'paykka_order_id') {
        return;
    }
    echo esc_html(paykka_get_gateway_order_id_display($order));
}

/**
 * 订单详情：Payment Order + Merchant Transaction ID（Hosted / Payments）。
 *
 * @param \WC_Order $order
 */
function paykka_render_order_admin_payment_ids($order)
{
    if (!$order || !is_a($order, 'WC_Order')) {
        return;
    }

    $ids = paykka_get_payment_ids($order);
    if ($ids['payment_order'] === '' && $ids['merchant_trans_id'] === '') {
        return;
    }

    echo '<div class="paykka-admin-payment-ids" style="margin-top:8px;">';
    if ($ids['payment_order'] !== '') {
        echo '<p class="form-field form-field-wide" style="margin:0 0 6px;">';
        echo '<strong>' . esc_html__('Payment Order', 'paykka-for-woocommerce') . ':</strong> ';
        echo '<code style="user-select:all;">' . esc_html($ids['payment_order']) . '</code>';
        echo '</p>';
    }
    if ($ids['merchant_trans_id'] !== '') {
        echo '<p class="form-field form-field-wide" style="margin:0 0 6px;">';
        echo '<strong>' . esc_html__('Merchant Transaction ID', 'paykka-for-woocommerce') . ':</strong> ';
        echo '<code style="user-select:all;">' . esc_html($ids['merchant_trans_id']) . '</code>';
        echo '</p>';
    }
    echo '</div>';
}
