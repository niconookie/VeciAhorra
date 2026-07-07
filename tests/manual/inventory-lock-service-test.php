<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Inventory\Services\InventoryLockService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertInventoryLock(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertInventoryLockSame(mixed $expected, mixed $actual): void
{
    assertInventoryLock(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

function assertInventoryLockInvalid(callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException('Se esperaba InvalidArgumentException.');
}

global $wpdb;

$inventoryRepository = new InventoryRepository();
$service = new InventoryLockService();
$table = $wpdb->prefix . Config::TABLE_PREFIX . 'inventory';
$transaction = $wpdb->query('START TRANSACTION');
assertInventoryLock($transaction !== false, 'No se inicio la transaccion.');

try {
    $now = current_time('mysql');
    $id = $inventoryRepository->create([
        'product_id' => random_int(30000000, 30999999),
        'minimarket_id' => random_int(31000000, 31999999),
        'price' => 1000.0,
        'stock' => 10,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $stock = static fn (): int => (int) $wpdb->get_var(
        $wpdb->prepare("SELECT stock FROM {$table} WHERE id = %d", $id)
    );

    assertInventoryLockSame(true, $service->checkAvailability($id, 10));
    assertInventoryLockSame(false, $service->checkAvailability($id, 11));

    assertInventoryLockSame(true, $service->lockStock($id, 4));
    assertInventoryLockSame(6, $stock());
    assertInventoryLockSame(false, $service->lockStock($id, 7));
    assertInventoryLockSame(6, $stock());

    assertInventoryLockSame(true, $service->lockStock($id, 2));
    assertInventoryLockSame(true, $service->lockStock($id, 3));
    assertInventoryLockSame(1, $stock());
    assertInventoryLockSame(false, $service->lockStock($id, 2));
    assertInventoryLockSame(1, $stock());

    assertInventoryLockSame(true, $service->releaseStock($id, 3));
    assertInventoryLockSame(4, $stock());

    assertInventoryLockSame(true, $service->commitStock($id, 3));
    assertInventoryLockSame(4, $stock());

    assertInventoryLockInvalid(fn () => $service->checkAvailability($id, 0));
    assertInventoryLockInvalid(fn () => $service->lockStock($id, -1));
    assertInventoryLockInvalid(fn () => $service->releaseStock($id, 0));
    assertInventoryLockInvalid(fn () => $service->commitStock(0, 1));

    $repositorySource = file_get_contents(
        dirname(__DIR__, 2)
        . '/app/Modules/Inventory/Repositories/InventoryLockRepository.php'
    );
    assertInventoryLock(
        is_string($repositorySource)
        && str_contains($repositorySource, 'stock = stock - %%d')
        && str_contains($repositorySource, 'stock >= %%d'),
        'El bloqueo no usa el UPDATE atomico esperado.'
    );

    echo "PASS inventory-lock-service-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
