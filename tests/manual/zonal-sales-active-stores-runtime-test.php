<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use VeciAhorra\Modules\ZonalAdmin\Identity\ZonalAdminRole;
use VeciAhorra\Modules\ZonalAdmin\Repositories\ZonalSalesRepository;

function activeSalesAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

global $wpdb;
$prefix = $wpdb->prefix . 'va_';
$run = 'zsal_' . bin2hex(random_bytes(5));
$now = current_time('mysql');
$user = 0;
$zone = 0;
$stores = [];
$orders = [];
$payments = [];

try {
    $admin = (int) $wpdb->get_var("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='{$wpdb->prefix}capabilities' AND meta_value LIKE '%administrator%' ORDER BY user_id LIMIT 1");
    $created = wp_create_user($run, wp_generate_password(30), $run . '@example.test');
    activeSalesAssert(! is_wp_error($created), 'No fue posible crear usuario zonal.');
    $user = (int) $created;
    (new WP_User($user))->set_role(ZonalAdminRole::ROLE);
    $wpdb->insert($prefix . 'service_zones', ['commune' => $run, 'name' => $run, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $zone = (int) $wpdb->insert_id;
    $wpdb->insert($prefix . 'zonal_admin_service_zones', ['user_id' => $user, 'service_zone_id' => $zone, 'created_at' => $now, 'created_by' => $admin]);

    $definitions = [
        'active_zero' => ['active', 'complete', $now],
        'active_sale' => ['active', 'complete', $now],
        'draft' => ['draft', 'draft', null],
        'in_review' => ['in_review', 'in_review', null],
        'observed' => ['observed', 'observed', null],
        'rejected' => ['rejected', 'rejected', null],
        'inactive' => ['inactive', 'complete', $now],
        'unapproved' => ['active', 'complete', null],
    ];
    foreach ($definitions as $key => [$status, $onboarding, $approved]) {
        $wpdb->insert($prefix . 'stores', ['business_name' => $run . ' ' . $key, 'legal_name' => $run, 'owner_name' => 'Fixture', 'rut' => substr(hash('sha256', $run . $key), 0, 16), 'email' => $run . $key . '@example.test', 'phone' => '0', 'status' => $status, 'onboarding_status' => $onboarding, 'approved_at' => $approved, 'created_at' => $now, 'updated_at' => $now]);
        $stores[$key] = (int) $wpdb->insert_id;
        $wpdb->insert($prefix . 'store_service_zones', ['store_id' => $stores[$key], 'zone_id' => $zone, 'assigned_by' => $admin, 'assigned_at' => $now]);
    }
    foreach (['active_sale', 'draft', 'in_review', 'observed', 'rejected', 'inactive', 'unapproved'] as $key) {
        $wpdb->insert($prefix . 'orders', ['customer_id' => $user, 'minimarket_id' => $stores[$key], 'total' => '1000', 'status' => 'paid', 'store_fulfillment_status' => 'awaiting_confirmation', 'created_at' => $now, 'updated_at' => $now]);
        $order = (int) $wpdb->insert_id; $orders[] = $order;
        $wpdb->insert($prefix . 'order_items', ['order_id' => $order, 'product_id' => 930000 + $order, 'inventory_id' => 940000 + $order, 'quantity' => 1, 'unit_price' => '1000', 'subtotal' => '1000', 'created_at' => $now, 'updated_at' => $now]);
        $wpdb->insert($prefix . 'payments', ['payment_reference' => $run . '-' . $key, 'customer_id' => $user, 'amount' => '1000', 'currency' => 'CLP', 'status' => 'paid', 'paid_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $payment = (int) $wpdb->insert_id; $payments[] = $payment;
        $wpdb->insert($prefix . 'payment_orders', ['payment_id' => $payment, 'order_id' => $order, 'created_at' => $now]);
    }
    $from = (new DateTimeImmutable($now))->modify('-1 day')->format('Y-m-d H:i:s');
    $to = (new DateTimeImmutable($now))->modify('+1 day')->format('Y-m-d H:i:s');
    $report = (new ZonalSalesRepository())->report($user, false, $from, $to, 'name', 'asc', 1, 20);
    $names = array_column($report['items'], 'minimarket');
    activeSalesAssert($names === [$run . ' active_sale', $run . ' active_zero'], 'Las filas no respetan el lifecycle compuesto: ' . wp_json_encode($names));
    activeSalesAssert($report['summary'] === ['active_stores' => 2, 'paid_orders' => 1, 'product_sales' => '1000.00', 'average_ticket' => '1000.00'], 'El resumen incluyó ventas de solicitudes: ' . wp_json_encode($report['summary']));
    activeSalesAssert($report['items'][1]['paid_orders'] === 0 && $report['items'][1]['product_sales'] === '0.00', 'Store activo sin ventas no fue incluido con cero.');
    echo "ZONAL_ACTIVE_STORES_SALES=PASS active_zero=INCLUDED active_sale=INCLUDED draft=EXCLUDED in_review=EXCLUDED observed=EXCLUDED rejected=EXCLUDED inactive=EXCLUDED unapproved=EXCLUDED\n";
} finally {
    if ($orders !== []) { $ids=implode(',',$orders); $wpdb->query("DELETE FROM {$prefix}payment_orders WHERE order_id IN ({$ids})"); $wpdb->query("DELETE FROM {$prefix}order_items WHERE order_id IN ({$ids})"); $wpdb->query("DELETE FROM {$prefix}orders WHERE id IN ({$ids})"); }
    if ($payments !== []) $wpdb->query("DELETE FROM {$prefix}payments WHERE id IN (" . implode(',', $payments) . ')');
    foreach ($stores as $store) $wpdb->delete($prefix . 'store_service_zones', ['store_id' => $store]);
    foreach ($stores as $store) $wpdb->delete($prefix . 'stores', ['id' => $store]);
    if ($user) { $wpdb->delete($prefix . 'zonal_admin_service_zones', ['user_id' => $user]); wp_delete_user($user); }
    if ($zone) $wpdb->delete($prefix . 'service_zones', ['id' => $zone]);
}
