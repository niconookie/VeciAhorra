<?php

declare(strict_types=1);

define('VECIAHORRA_PUBLIC_REGISTRATION_ENABLED', false);
define('VECIAHORRA_PUBLIC_COMMERCE_ENABLED', false);
require_once dirname(__DIR__, 5) . '/wp-load.php';

function prelaunchRuntimeAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

global $wpdb;
$tables = ['cart_items', 'reservations', 'orders', 'payments', 'payment_sessions'];
$before = [];
foreach ($tables as $table) $before[$table] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}va_{$table}");
$administrator = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
prelaunchRuntimeAssert(isset($administrator[0]), 'No existe administrador para probar la ruta autenticada.');
wp_set_current_user((int) $administrator[0]);

$requests = [
    new WP_REST_Request('POST', '/veciahorra/v1/cart/items'),
    new WP_REST_Request('POST', '/veciahorra/v1/checkout'),
    new WP_REST_Request('POST', '/veciahorra/v1/payments/session'),
    new WP_REST_Request('POST', '/veciahorra/v1/service-provider/enroll'),
];
foreach ($requests as $request) {
    $request->set_header('content-type', 'application/json');
    $request->set_body('{}');
    $response = rest_do_request($request);
    prelaunchRuntimeAssert($response->get_status() === 503, $request->get_route() . ' no devolvi&oacute; 503.');
}
foreach ($tables as $table) {
    $after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}va_{$table}");
    prelaunchRuntimeAssert($after === $before[$table], $table . ' fue modificado.');
}

echo "PRELAUNCH_DISABLED_RUNTIME=PASS\nWRITES=0\n";
