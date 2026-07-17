<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertPurchases(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

function purchaseRequest(string $route, array $query = []): WP_REST_Response
{
    $request = new WP_REST_Request('GET', $route);
    $request->set_query_params($query);
    return rest_do_request($request);
}

function purchasePersistenceSnapshot(wpdb $wpdb, string $prefix): array
{
    $tables = [
        'checkouts', 'checkout_orders', 'orders', 'order_items',
        'payment_sessions', 'payment_origin_contexts', 'webpay_returns',
        'payment_reconciliations', 'payments', 'payment_orders',
        'business_completions', 'business_completion_orders',
        'delivery_completions', 'deliveries', 'fulfillment_completions',
    ];
    $result = [];
    foreach ($tables as $table) {
        $name = $prefix . $table;
        $result[$name] = hash('sha256', serialize($wpdb->get_results(
            "SELECT * FROM `{$name}` ORDER BY id ASC",
            ARRAY_A
        )));
    }
    foreach (['actionscheduler_actions', 'actionscheduler_claims', 'actionscheduler_groups', 'actionscheduler_logs'] as $table) {
        $name = $wpdb->prefix . $table;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === $name) {
            $result[$name] = hash('sha256', serialize($wpdb->get_results(
                "SELECT * FROM `{$name}` ORDER BY 1 ASC",
                ARRAY_A
            )));
        }
    }

    return $result;
}

global $wpdb;
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$users = get_users(['role' => 'administrator', 'number' => 2, 'fields' => 'ids']);
assertPurchases($users !== [], 'Falta usuario de prueba.');
$owner = (int) $users[0];
$foreign = isset($users[1]) ? (int) $users[1] : $owner + 100000;
$now = current_time('mysql');
$public = 'chk_' . str_repeat('A', 43);
$foreignPublic = 'chk_' . str_repeat('B', 43);
$wooPublic = 'chk_' . str_repeat('C', 43);
$unknownPublic = 'chk_' . str_repeat('D', 43);

$wpdb->query('START TRANSACTION');
try {
    $insertCheckout = static function (string $id, int $user, string $total) use ($wpdb, $prefix, $now): int {
        $wpdb->insert($prefix . 'checkouts', [
            'public_id' => $id, 'owner_type' => 'user', 'user_id' => $user,
            'session_id' => null, 'status' => 'pending', 'fulfillment_method' => 'pickup',
            'currency' => 'CLP', 'total_amount' => $total, 'created_at' => $now,
            'updated_at' => $now, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        return (int) $wpdb->insert_id;
    };
    $checkoutId = $insertCheckout($public, $owner, '5000.00');
    $foreignCheckoutId = $insertCheckout($foreignPublic, $foreign, '1000.00');
    $wooCheckoutId = $insertCheckout($wooPublic, $owner, '1000.00');
    $unknownCheckoutId = $insertCheckout($unknownPublic, $owner, '1000.00');
    $insertOrigin = static function (int $checkoutId, string $publicId, string $origin, int $token, int $amount) use ($wpdb, $prefix, $now): void {
        $sessionPublic = 'ps_' . str_pad((string) $token, 40, '0', STR_PAD_LEFT);
        $wpdb->insert($prefix . 'payment_sessions', [
            'public_id' => $sessionPublic, 'checkout_id' => $checkoutId,
            'payment_id' => null, 'idempotency_key' => 'key-' . $token,
            'request_fingerprint' => hash('sha256', 'request-' . $token),
            'status' => 'pending', 'currency' => 'CLP', 'amount' => $amount . '.00',
            'created_at' => $now, 'updated_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        $wpdb->insert($prefix . 'payment_origin_contexts', [
            'public_id' => 'poc_' . str_pad(dechex($token), 40, '0', STR_PAD_LEFT),
            'site_scope' => str_repeat('s', 16), 'origin' => $origin,
            'origin_resource_id' => $publicId, 'gateway_id' => 'webpay_plus',
            'payment_attempt_id' => $sessionPublic, 'origin_key' => hash('sha256', 'origin-' . $token),
            'amount_clp' => $amount, 'currency' => 'CLP', 'environment' => 'integration',
            'merchant_identity_hash' => hash('sha256', 'merchant'),
            'buy_order' => 'VA' . str_repeat('A', 24),
            'financial_session_id' => 'VA-' . str_repeat('B', 58),
            'token_hash' => null, 'context_version' => 1,
            'created_at' => $now, 'updated_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
    };
    $insertOrigin($checkoutId, $public, 'veciahorra_checkout', 29101, 5000);
    $insertOrigin($wooCheckoutId, $wooPublic, 'woocommerce', 29102, 1000);
    $insertOrigin($unknownCheckoutId, $unknownPublic, 'unknown', 29103, 1000);
    $orderIds = [];
    foreach ([[901, '2000.00'], [902, '3000.00']] as [$market, $total]) {
        $wpdb->insert($prefix . 'orders', ['customer_id' => $owner, 'minimarket_id' => $market, 'total' => $total, 'status' => 'reserved', 'reservation_expires_at' => null, 'created_at' => $now, 'updated_at' => $now]);
        $orderId = (int) $wpdb->insert_id;
        $orderIds[] = $orderId;
        $wpdb->insert($prefix . 'checkout_orders', ['checkout_id' => $checkoutId, 'order_id' => $orderId, 'created_at' => $now]);
        $wpdb->insert($prefix . 'order_items', ['order_id' => $orderId, 'product_id' => 999999991, 'inventory_id' => 999999992, 'quantity' => 1, 'unit_price' => $total, 'subtotal' => $total, 'created_at' => $now, 'updated_at' => $now]);
    }
    $wpdb->insert($prefix . 'orders', ['customer_id' => $foreign, 'minimarket_id' => 903, 'total' => '1000.00', 'status' => 'reserved', 'reservation_expires_at' => null, 'created_at' => $now, 'updated_at' => $now]);
    $foreignOrder = (int) $wpdb->insert_id;
    $wpdb->insert($prefix . 'checkout_orders', ['checkout_id' => $foreignCheckoutId, 'order_id' => $foreignOrder, 'created_at' => $now]);
    $wpdb->insert($prefix . 'order_items', ['order_id' => $foreignOrder, 'product_id' => 999999993, 'inventory_id' => 999999994, 'quantity' => 1, 'unit_price' => '1000.00', 'subtotal' => '1000.00', 'created_at' => $now, 'updated_at' => $now]);

    $routes = rest_get_server()->get_routes();
    assertPurchases(isset($routes['/veciahorra/v1/customer-panel/purchases']), 'Falta ruta de listado.');
    wp_set_current_user(0);
    assertPurchases(in_array(purchaseRequest('/veciahorra/v1/customer-panel/purchases')->get_status(), [401, 403], true), 'Invitado accedió al listado.');
    wp_set_current_user($owner);
    $list = purchaseRequest('/veciahorra/v1/customer-panel/purchases');
    assertPurchases($list->get_status() === 200, 'Listado no respondió 200.');
    assertPurchases(str_contains((string) ($list->get_headers()['Cache-Control'] ?? ''), 'private'), 'Listado sin cache privada.');
    assertPurchases(str_contains((string) ($list->get_headers()['Cache-Control'] ?? ''), 'no-store'), 'Listado permite cache persistente.');
    assertPurchases(($list->get_headers()['Vary'] ?? null) === 'Cookie', 'Listado no varía por cookie.');
    $items = $list->get_data()['data'] ?? [];
    $owned = array_values(array_filter($items, static fn (array $item): bool => $item['checkout_public_id'] === $public));
    assertPurchases(count($owned) === 1, 'Checkout propio no aparece una sola vez.');
    assertPurchases($owned[0]['order_count'] === 2 && $owned[0]['minimarket_count'] === 2, 'No agrupó Orders.');
    assertPurchases(! in_array($foreignPublic, array_column($items, 'checkout_public_id'), true), 'Expuso Checkout ajeno.');
    assertPurchases(! in_array($wooPublic, array_column($items, 'checkout_public_id'), true), 'Expuso origen WooCommerce.');
    assertPurchases(! in_array($unknownPublic, array_column($items, 'checkout_public_id'), true), 'Expuso origen desconocido.');
    $ownershipOverride = purchaseRequest('/veciahorra/v1/customer-panel/purchases', ['user_id' => $foreign, 'customer_id' => $foreign]);
    assertPurchases($ownershipOverride->get_data() === $list->get_data(), 'Parámetros del cliente alteraron ownership.');
    $detail = purchaseRequest('/veciahorra/v1/customer-panel/purchases/' . $public);
    assertPurchases($detail->get_status() === 200, 'Detalle propio no respondió 200.');
    $data = $detail->get_data()['data'] ?? [];
    assertPurchases(count($data['orders'] ?? []) === 2, 'Detalle no contiene dos grupos.');
    assertPurchases(($data['summary']['total'] ?? null) === '5000.00', 'Total no usa Checkout.');
    assertPurchases(($data['orders'][0]['items'][0]['unit_price'] ?? null) === '2000.00', 'Precio histórico incorrecto.');
    assertPurchases(($data['orders'][0]['items'][0]['name_historical'] ?? true) === false, 'Nombre actual fue marcado histórico.');
    $wpdb->insert($prefix . 'products', [
        'id' => 999999991, 'woo_product_id' => null, 'name' => 'Nombre decorativo actual',
        'slug' => 'nombre-decorativo-actual', 'sku' => null, 'description' => null,
        'category_id' => null, 'brand_id' => null, 'unit_id' => null, 'image_id' => null,
        'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $decorative = purchaseRequest('/veciahorra/v1/customer-panel/purchases/' . $public)->get_data()['data'] ?? [];
    assertPurchases(($decorative['orders'][0]['items'][0]['name'] ?? null) === 'Nombre decorativo actual', 'No proyectó el nombre actual decorativo.');
    assertPurchases(($decorative['orders'][0]['items'][0]['unit_price'] ?? null) === '2000.00', 'El catálogo alteró el precio histórico.');
    $wpdb->update($prefix . 'products', ['name' => 'Nombre decorativo modificado'], ['id' => 999999991]);
    $modified = purchaseRequest('/veciahorra/v1/customer-panel/purchases/' . $public)->get_data()['data'] ?? [];
    assertPurchases(($modified['orders'][0]['items'][0]['name'] ?? null) === 'Nombre decorativo modificado', 'El nombre actual no se trató como decorativo.');
    assertPurchases(($modified['orders'][0]['items'][0]['unit_price'] ?? null) === '2000.00', 'Modificar catálogo alteró snapshots durables.');
    $wpdb->delete($prefix . 'products', ['id' => 999999991]);
    $removed = purchaseRequest('/veciahorra/v1/customer-panel/purchases/' . $public)->get_data()['data'] ?? [];
    assertPurchases(str_starts_with((string) ($removed['orders'][0]['items'][0]['name'] ?? ''), 'Producto '), 'Producto eliminado no usa fallback neutro.');
    assertPurchases(($removed['orders'][0]['items'][0]['unit_price'] ?? null) === '2000.00', 'Eliminar producto alteró snapshots durables.');
    $notFoundContract = null;
    foreach ([$foreignPublic, $wooPublic, $unknownPublic, 'chk_invalid', 'chk_' . str_repeat('Z', 43)] as $unavailable) {
        $missing = purchaseRequest('/veciahorra/v1/customer-panel/purchases/' . $unavailable);
        assertPurchases($missing->get_status() === 404, 'Respuesta no uniforme para recurso no disponible.');
        assertPurchases(str_contains((string) ($missing->get_headers()['Cache-Control'] ?? ''), 'no-store'), '404 sensible permite cache.');
        $notFoundContract ??= $missing->get_data();
        assertPurchases($missing->get_data() === $notFoundContract, '404 distinguible por existencia, owner u origen.');
    }
    $sameTimestampIds = [];
    for ($index = 1; $index <= 21; $index++) {
        $limitedPublic = 'chk_' . str_pad((string) $index, 43, '0', STR_PAD_LEFT);
        $wpdb->insert($prefix . 'checkouts', [
            'public_id' => $limitedPublic, 'owner_type' => 'user', 'user_id' => $owner,
            'session_id' => null, 'status' => 'pending', 'fulfillment_method' => 'pickup',
            'currency' => 'CLP', 'total_amount' => '1.00', 'created_at' => '2099-01-01 00:00:00',
            'updated_at' => '2099-01-01 00:00:00', 'expires_at' => '2099-01-01 01:00:00',
        ]);
        $sameTimestampIds[(int) $wpdb->insert_id] = $limitedPublic;
    }
    krsort($sameTimestampIds, SORT_NUMERIC);
    $queriesBeforeList = $wpdb->num_queries;
    $limitedResponse = purchaseRequest('/veciahorra/v1/customer-panel/purchases');
    $listQueryCount = $wpdb->num_queries - $queriesBeforeList;
    $limited = $limitedResponse->get_data()['data'] ?? [];
    assertPurchases(count($limited) === 20, 'El listado no aplica LIMIT 20 exacto.');
    assertPurchases($listQueryCount <= 10, 'El listado reintrodujo N+1 por Checkout.');
    assertPurchases(
        array_column($limited, 'checkout_public_id') === array_slice(array_values($sameTimestampIds), 0, 20),
        'El desempate del LIMIT 20 no usa id DESC de forma estable.'
    );
    $before = purchasePersistenceSnapshot($wpdb, $prefix);
    purchaseRequest('/veciahorra/v1/customer-panel/purchases');
    purchaseRequest('/veciahorra/v1/customer-panel/purchases/' . $public);
    purchaseRequest('/veciahorra/v1/customer-panel/purchases');
    purchaseRequest('/veciahorra/v1/customer-panel/purchases/' . $public);
    $after = purchasePersistenceSnapshot($wpdb, $prefix);
    assertPurchases($before === $after, 'La lectura modificó autoridades o Action Scheduler.');
    $json = wp_json_encode($data);
    foreach (['lease_owner', 'fingerprint', 'token_hash', 'order_id', 'checkout_id', 'payment_id', 'delivery_id'] as $forbidden) {
        assertPurchases(! str_contains((string) $json, '"' . $forbidden . '"'), 'Expuso campo interno: ' . $forbidden);
    }
    echo "PASS customer-purchases-rest-test (list_queries={$listQueryCount})\n";
} finally {
    $wpdb->query('ROLLBACK');
    wp_set_current_user(0);
}
