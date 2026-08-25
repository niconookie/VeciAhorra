<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

global $wpdb;
$prefix = $wpdb->prefix . 'va_';
$run = (string) ($argv[1] ?? '');
$state = (string) ($argv[2] ?? '');

if (! preg_match('/^zsui_[A-Za-z0-9_]+$/', $run) || ! in_array($state, ['empty', 'active'], true)) {
    throw new RuntimeException('Invalid browser fixture state.');
}

$storeIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
    "SELECT id FROM {$prefix}stores WHERE business_name LIKE %s",
    $run . ' %'
)));

if ($storeIds !== []) {
    $stores = implode(',', $storeIds);
    $orderIds = array_map('intval', $wpdb->get_col("SELECT id FROM {$prefix}orders WHERE minimarket_id IN ({$stores})"));
    if ($orderIds !== []) {
        $orders = implode(',', $orderIds);
        $paymentIds = array_map('intval', $wpdb->get_col("SELECT payment_id FROM {$prefix}payment_orders WHERE order_id IN ({$orders})"));
        $wpdb->query("DELETE FROM {$prefix}payment_orders WHERE order_id IN ({$orders})");
        if ($paymentIds !== []) {
            $wpdb->query("DELETE FROM {$prefix}payments WHERE id IN (" . implode(',', $paymentIds) . ')');
        }
        $wpdb->query("DELETE FROM {$prefix}order_items WHERE order_id IN ({$orders})");
        $wpdb->query("DELETE FROM {$prefix}orders WHERE id IN ({$orders})");
    }
}

if ($state === 'empty' && $storeIds !== []) {
    $stores = implode(',', $storeIds);
    $wpdb->query("DELETE FROM {$prefix}store_service_zones WHERE store_id IN ({$stores})");
    $wpdb->query("DELETE FROM {$prefix}stores WHERE id IN ({$stores})");
}

echo 'PASS';
