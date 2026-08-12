<?php

declare(strict_types=1);

require dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Core\Config;

global $wpdb;
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$customer = get_user_by('login', 'va_demo_carolina');
$result = ['customer_user_id' => $customer instanceof WP_User ? $customer->ID : null];

foreach (['checkouts', 'orders', 'order_items', 'stock_reservations', 'payment_sessions', 'deliveries'] as $suffix) {
    $table = $prefix . $suffix;
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    $result[$suffix] = ['exists' => $exists];
    if ($exists) {
        $result[$suffix]['columns'] = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`");
        $result[$suffix]['latest'] = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY id DESC LIMIT 3", ARRAY_A);
    }
}

if ($customer instanceof WP_User) {
    $result['customer_scoped'] = [
        'checkouts' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$prefix}checkouts` WHERE user_id = %d",
            $customer->ID
        )),
        'orders' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$prefix}orders` WHERE customer_id = %d",
            $customer->ID
        )),
        'deliveries' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$prefix}deliveries` WHERE customer_id = %d",
            $customer->ID
        )),
        'latest_checkout' => $wpdb->get_row($wpdb->prepare(
            "SELECT id, public_id, status, fulfillment_method, total_amount,"
            . " delivery_recipient_name, delivery_contact_phone, delivery_address_line1,"
            . " delivery_commune, delivery_reference, delivery_notes"
            . " FROM `{$prefix}checkouts` WHERE user_id = %d ORDER BY id DESC LIMIT 1",
            $customer->ID
        ), ARRAY_A),
        'latest_order' => $wpdb->get_row($wpdb->prepare(
            "SELECT id, minimarket_id, total, status FROM `{$prefix}orders`"
            . " WHERE customer_id = %d ORDER BY id DESC LIMIT 1",
            $customer->ID
        ), ARRAY_A),
    ];
    $checkoutId = (int) ($result['customer_scoped']['latest_checkout']['id'] ?? 0);
    $orderId = (int) ($result['customer_scoped']['latest_order']['id'] ?? 0);
    $result['customer_scoped']['payment_session'] = $checkoutId > 0
        ? $wpdb->get_row($wpdb->prepare(
            "SELECT id, public_id, status, provider, amount FROM `{$prefix}payment_sessions`"
            . " WHERE checkout_id = %d ORDER BY id DESC LIMIT 1",
            $checkoutId
        ), ARRAY_A)
        : null;
    $result['customer_scoped']['reservations'] = $orderId > 0
        ? $wpdb->get_results($wpdb->prepare(
            "SELECT id, order_id, inventory_id, quantity, status, expires_at"
            . " FROM `{$prefix}reservations` WHERE order_id = %d ORDER BY id",
            $orderId
        ), ARRAY_A)
        : [];
    $result['customer_scoped']['attempts'] = $wpdb->get_results($wpdb->prepare(
        "SELECT c.id checkout_db_id, c.public_id checkout_id, c.status checkout_status,"
        . " o.id order_id, o.status order_status, ps.id payment_session_db_id,"
        . " ps.public_id payment_session_id, ps.status payment_session_status"
        . " FROM `{$prefix}checkouts` c"
        . " LEFT JOIN `{$prefix}checkout_orders` co ON co.checkout_id = c.id"
        . " LEFT JOIN `{$prefix}orders` o ON o.id = co.order_id"
        . " LEFT JOIN `{$prefix}payment_sessions` ps ON ps.checkout_id = c.id"
        . " WHERE c.user_id = %d ORDER BY c.id",
        $customer->ID
    ), ARRAY_A);
}

echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
