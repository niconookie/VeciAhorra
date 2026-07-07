<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Controllers\OrderController;
use VeciAhorra\Modules\Orders\Services\OrderService;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertOrderController(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertOrderControllerSame(mixed $expected, mixed $actual): void
{
    assertOrderController(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

global $wpdb;

$controller = new OrderController(new OrderService());
$transaction = $wpdb->query('START TRANSACTION');
assertOrderController($transaction !== false, 'No se inicio la transaccion.');

try {
    $customerId = random_int(13000000, 13999999);
    $minimarketId = random_int(14000000, 14999999);
    $inventoryRepository = new InventoryRepository();
    $now = current_time('mysql');
    $firstInventoryId = $inventoryRepository->create([
        'product_id' => 301, 'minimarket_id' => $minimarketId,
        'price' => 500.0, 'stock' => 10, 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $secondInventoryId = $inventoryRepository->create([
        'product_id' => 302, 'minimarket_id' => $minimarketId,
        'price' => 250.0, 'stock' => 10, 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $stored = $controller->store([
        'customer_id' => $customerId,
        'minimarket_id' => $minimarketId,
        'items' => [
            [
                'product_id' => 301,
                'inventory_id' => $firstInventoryId,
                'quantity' => 2,
                'unit_price' => 750.25,
            ],
            [
                'product_id' => 302,
                'inventory_id' => $secondInventoryId,
                'quantity' => 1,
                'unit_price' => 100.0,
            ],
        ],
    ]);

    assertOrderControllerSame(true, $stored['success'] ?? null);
    $order = $stored['data'] ?? null;
    assertOrderController(is_array($order), 'Store no serializo el pedido.');
    $orderId = (int) ($order['id'] ?? 0);
    assertOrderController($orderId > 0, 'Store no retorno ID valido.');
    assertOrderControllerSame('reserved', $order['status']);
    assertOrderControllerSame('1600.50', $order['total']);
    assertOrderControllerSame(2, count($order['items']));
    assertOrderControllerSame(1500.50, $order['items'][0]['subtotal']);

    $shown = $controller->show($orderId);
    assertOrderControllerSame(true, $shown['success'] ?? null);
    assertOrderControllerSame($orderId, (int) $shown['data']['id']);
    assertOrderControllerSame($customerId, (int) $shown['data']['customer_id']);

    $index = $controller->index([
        'customer_id' => $customerId,
        'minimarket_id' => $minimarketId,
        'status' => 'reserved',
    ]);
    assertOrderControllerSame(true, $index['success'] ?? null);
    assertOrderControllerSame(1, count($index['data']));
    assertOrderControllerSame($orderId, (int) $index['data'][0]['id']);

    $missing = $controller->show(PHP_INT_MAX);
    assertOrderControllerSame(false, $missing['success'] ?? null);
    assertOrderControllerSame(
        'order_not_found',
        $missing['error']['code'] ?? null
    );

    $invalid = $controller->store([
        'customer_id' => 1,
        'minimarket_id' => 2,
        'items' => [],
    ]);
    assertOrderControllerSame(false, $invalid['success'] ?? null);
    assertOrderControllerSame(
        'validation_error',
        $invalid['error']['code'] ?? null
    );

    $originalPrefix = $wpdb->prefix;
    $wpdb->suppress_errors(true);

    try {
        $wpdb->prefix = 'missing_order_controller_' . uniqid() . '_';
        $persistence = $controller->store([
            'customer_id' => 1,
            'minimarket_id' => 2,
            'items' => [[
                'product_id' => 1,
                'inventory_id' => 1,
                'quantity' => 1,
                'unit_price' => 1.0,
            ]],
        ]);
    } finally {
        $wpdb->prefix = $originalPrefix;
        $wpdb->suppress_errors(false);
    }

    assertOrderControllerSame(false, $persistence['success'] ?? null);
    assertOrderControllerSame(
        'validation_error',
        $persistence['error']['code'] ?? null
    );

    $source = file_get_contents(
        dirname(__DIR__, 2)
        . '/app/Modules/Orders/Controllers/OrderController.php'
    );
    assertOrderController(
        is_string($source) && ! str_contains($source, '$wpdb'),
        'OrderController contiene acceso a $wpdb.'
    );
    assertOrderController(
        preg_match('/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM)\b/i', $source)
            !== 1,
        'OrderController contiene SQL.'
    );
    assertOrderController(
        ! str_contains($source, 'unit_price')
            && ! str_contains($source, 'subtotal')
            && ! str_contains($source, 'reservation_expires_at'),
        'OrderController contiene reglas de negocio.'
    );

    echo "PASS order-controller-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
