<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertOrderRepository(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertOrderRepositorySame(mixed $expected, mixed $actual): void
{
    assertOrderRepository(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

global $wpdb;

$repository = new OrderRepository();
$transaction = $wpdb->query('START TRANSACTION');
assertOrderRepository($transaction !== false, 'No se inicio la transaccion.');

try {
    $customerId = random_int(9000000, 9999999);
    $minimarketId = random_int(10000000, 10999999);
    $now = current_time('mysql');
    $firstId = $repository->create([
        'customer_id' => $customerId,
        'minimarket_id' => $minimarketId,
        'total' => 4500.0,
        'status' => 'reserved',
        'reservation_expires_at' => '2030-01-01 12:00:00',
        'created_at' => $now,
        'updated_at' => $now,
        'ignored' => 'no persistir',
    ]);
    assertOrderRepository($firstId > 0, 'Create no retorno ID valido.');

    $repository->createItems($firstId, [
        [
            'product_id' => 101,
            'inventory_id' => 201,
            'quantity' => 2,
            'unit_price' => 1500.0,
            'subtotal' => 3000.0,
            'created_at' => $now,
            'updated_at' => $now,
            'ignored' => true,
        ],
        [
            'product_id' => 102,
            'inventory_id' => 202,
            'quantity' => 1,
            'unit_price' => 1500.0,
            'subtotal' => 1500.0,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $itemsTable = $wpdb->prefix . Config::TABLE_PREFIX . 'order_items';
    $items = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$itemsTable} WHERE order_id = %d ORDER BY id ASC",
            $firstId
        ),
        ARRAY_A
    );
    assertOrderRepositorySame(2, count($items));
    assertOrderRepositorySame('1500.00', $items[0]['unit_price']);
    assertOrderRepositorySame('3000.00', $items[0]['subtotal']);
    assertOrderRepositorySame(2, (int) $items[0]['quantity']);
    assertOrderRepository(! isset($items[0]['ignored']), 'Se persistio un extra.');

    $found = $repository->find($firstId);
    assertOrderRepository(is_array($found), 'Find no encontro el pedido.');
    assertOrderRepositorySame($firstId, (int) $found['id']);
    assertOrderRepositorySame($customerId, (int) $found['customer_id']);
    assertOrderRepositorySame(null, $repository->find(PHP_INT_MAX));

    $secondId = $repository->create([
        'customer_id' => $customerId + 1,
        'minimarket_id' => $minimarketId + 1,
        'total' => 100.0,
        'status' => 'confirmed',
        'reservation_expires_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $all = $repository->list();
    $allIds = array_map('intval', array_column($all, 'id'));
    assertOrderRepository(
        in_array($firstId, $allIds, true)
            && in_array($secondId, $allIds, true),
        'List no incluyo los pedidos creados.'
    );
    assertOrderRepository(
        array_search($secondId, $allIds, true)
            < array_search($firstId, $allIds, true),
        'List no ordeno por id DESC.'
    );

    $byCustomer = $repository->list(['customer_id' => $customerId]);
    assertOrderRepositorySame(1, count($byCustomer));
    assertOrderRepositorySame($firstId, (int) $byCustomer[0]['id']);

    $byMinimarket = $repository->list([
        'minimarket_id' => $minimarketId + 1,
    ]);
    assertOrderRepositorySame(1, count($byMinimarket));
    assertOrderRepositorySame($secondId, (int) $byMinimarket[0]['id']);

    $byStatus = $repository->list(['status' => 'reserved']);
    assertOrderRepository(
        in_array(
            $firstId,
            array_map('intval', array_column($byStatus, 'id')),
            true
        ),
        'El filtro status no encontro el pedido.'
    );
    assertOrderRepositorySame(
        [],
        $repository->list(['status' => "reserved' OR 1=1 --"])
    );

    $source = file_get_contents(
        dirname(__DIR__, 2)
        . '/app/Modules/Orders/Repositories/OrderRepository.php'
    );
    assertOrderRepository(
        is_string($source) && str_contains($source, '$this->db()->prepare'),
        'OrderRepository no usa consultas preparadas.'
    );
    assertOrderRepository(
        ! str_contains($source, "'wp_va_orders'")
            && ! str_contains($source, "'wp_va_order_items'"),
        'OrderRepository hardcodea el prefijo fisico.'
    );

    echo "PASS order-repository-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
