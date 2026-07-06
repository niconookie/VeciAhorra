<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertInventoryTrue(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertInventorySame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

global $wpdb;

$repository = new InventoryRepository();
$table = $wpdb->prefix . Config::TABLE_PREFIX . 'inventory';
$transactionStarted = $wpdb->query('START TRANSACTION');

assertInventoryTrue(
    $transactionStarted !== false,
    'No fue posible iniciar la transaccion.'
);

try {
    assertInventoryTrue(
        is_array($repository->paginate([])),
        'Paginate con filtros vacios no retorno un array.'
    );
    assertInventoryTrue(
        is_int($repository->count([])),
        'Count con filtros vacios no retorno un entero.'
    );

    $now = '2026-07-06 12:00:00';
    $productId = random_int(100000, 999999);
    $minimarketId = random_int(100000, 999999);
    $id = $repository->create([
        'product_id' => $productId,
        'minimarket_id' => $minimarketId,
        'price' => 1490.50,
        'stock' => 8,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
        'unsafe_column' => 'no debe persistirse',
    ]);

    assertInventoryTrue($id > 0, 'Create no retorno un ID valido.');
    assertInventorySame(
        1,
        (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE id = %d",
                $id
            )
        )
    );

    $found = $repository->find($id);
    assertInventoryTrue(is_array($found), 'Find no retorno un array.');
    assertInventorySame($productId, (int) $found['product_id']);
    assertInventorySame($minimarketId, (int) $found['minimarket_id']);

    $byRelation = $repository->findByProductAndMinimarket(
        $productId,
        $minimarketId
    );
    assertInventorySame($id, (int) $byRelation['id']);

    $page = $repository->paginate([
        'page' => 1,
        'per_page' => 10,
        'product_id' => $productId,
        'minimarket_id' => $minimarketId,
        'status' => 'active',
        'search' => (string) $productId,
    ]);
    assertInventoryTrue(is_array($page), 'Paginate no retorno un array.');
    assertInventorySame(1, count($page));
    assertInventorySame(1, $repository->count([
        'product_id' => $productId,
        'status' => 'active',
    ]));

    assertInventorySame(
        0,
        $repository->count([
            'status' => "active' OR 1=1 --",
        ])
    );
    assertInventorySame(
        0,
        $repository->count([
            'search' => "%' OR 1=1 --",
        ])
    );

    assertInventoryTrue(
        $repository->update($id, [
            'price' => 1790.25,
            'stock' => 3,
            'status' => 'inactive',
            'updated_at' => '2026-07-06 13:00:00',
            'product_id' => PHP_INT_MAX,
        ]),
        'Update no modifico el registro.'
    );

    $updated = $repository->find($id);
    assertInventorySame('1790.25', $updated['price']);
    assertInventorySame(3, (int) $updated['stock']);
    assertInventorySame('inactive', $updated['status']);
    assertInventorySame($productId, (int) $updated['product_id']);

    assertInventoryTrue(
        $repository->delete($id),
        'Delete no elimino el registro.'
    );
    assertInventorySame(null, $repository->find($id));
    assertInventorySame(false, $repository->delete($id));

    echo "PASS inventory-repository-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
