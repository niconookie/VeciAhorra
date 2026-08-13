<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once __DIR__ . '/support/training-courier-demo-scenario-support.php';

global $wpdb;
$context = vaCourierDemoContext();
$rows = vaCourierDemoValidate($context);
$orderIds = array_map(static fn(array $row): int => (int) $row['order_id'], $rows);
$deliveryIds = array_map(static fn(array $row): int => (int) $row['delivery_id'], $rows);
$checkoutIds = array_map(static fn(array $row): int => (int) $row['checkout_id'], $rows);
$wpdb->query('START TRANSACTION');
try {
    foreach ($deliveryIds as $id) $wpdb->delete($context['prefix'] . 'delivery_tracking', ['delivery_id' => $id]);
    foreach ($deliveryIds as $id) vaCourierDemoAssert($wpdb->delete($context['prefix'] . 'deliveries', ['id' => $id]) === 1, "Cleanup Delivery {$id} falló");
    foreach ($orderIds as $id) vaCourierDemoAssert($wpdb->delete($context['prefix'] . 'order_items', ['order_id' => $id]) === 1, "Cleanup OrderItem {$id} falló");
    foreach ($checkoutIds as $id) vaCourierDemoAssert($wpdb->delete($context['prefix'] . 'checkout_orders', ['checkout_id' => $id]) === 1, "Cleanup CheckoutOrder {$id} falló");
    foreach ($checkoutIds as $id) vaCourierDemoAssert($wpdb->delete($context['prefix'] . 'checkouts', ['id' => $id]) === 1, "Cleanup Checkout {$id} falló");
    foreach ($orderIds as $id) vaCourierDemoAssert($wpdb->delete($context['prefix'] . 'orders', ['id' => $id]) === 1, "Cleanup Order {$id} falló");
    vaCourierDemoAssert(vaCourierDemoRows($context) === [], 'Cleanup dejó recursos propios.');
    $wpdb->query('COMMIT');
} catch (Throwable $exception) {
    $wpdb->query('ROLLBACK');
    throw $exception;
}
echo 'PASS CLEANUP_AVAILABLE=yes OWNED_RESOURCES_ONLY=yes REMAINING=0', PHP_EOL;
