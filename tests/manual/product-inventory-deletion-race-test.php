<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Inventory\Exceptions\InventoryValidationException;
use VeciAhorra\Modules\Inventory\Services\InventoryService;
use VeciAhorra\Modules\Products\Domain\ProductReferenceInspection;
use VeciAhorra\Modules\Products\Exceptions\ProductDeletionException;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Products\Services\ProductService;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function raceSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\nEsperado: %s\nRecibido: %s",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function raceException(callable $operation, string $class): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($exception instanceof $class) {
            return $exception;
        }

        throw $exception;
    }

    throw new RuntimeException("Se esperaba {$class}.");
}

function startRaceWorker(
    string $mode,
    int $productId,
    int $storeId,
    string $updatedAt
): array {
    $readyFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'va-product-inventory-race-' . bin2hex(random_bytes(8));
    $pipes = [];
    $process = proc_open([
        PHP_BINARY,
        __DIR__ . '/product-inventory-deletion-race-worker.php',
        $mode,
        (string) $productId,
        (string) $storeId,
        $updatedAt,
        $readyFile,
    ], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    raceSame(true, is_resource($process), 'No inicio el worker de carrera.');
    fclose($pipes[0]);

    for ($attempt = 0; $attempt < 200 && ! is_file($readyFile); $attempt++) {
        usleep(20000);
    }

    if (! is_file($readyFile)) {
        $stderr = trim((string) stream_get_contents($pipes[2]));
        throw new RuntimeException('Worker no llego al lock: ' . $stderr);
    }

    unlink($readyFile);

    return [$process, $pipes];
}

function finishRaceWorker(array $worker): void
{
    [$process, $pipes] = $worker;
    $stdout = trim((string) stream_get_contents($pipes[1]));
    $stderr = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    raceSame(
        [0, '', ''],
        [$exit, $stdout, $stderr],
        'Worker de carrera fallo.'
    );
}

global $wpdb;

$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$productsTable = $prefix . 'products';
$storesTable = $prefix . 'stores';
$inventoryTable = $prefix . 'inventory';
$createdProducts = [];
$createdStores = [];
$token = bin2hex(random_bytes(6));
$now = current_time('mysql');
$products = new ProductRepository();
$productService = new ProductService();
$inventoryService = new InventoryService();
$sequence = 0;
$newProduct = static function () use (
    &$sequence,
    &$createdProducts,
    $products,
    $token,
    $now
): int {
    $sequence++;
    $id = $products->create([
        'name' => 'Inventory race ' . $sequence,
        'slug' => 'inventory-race-' . $token . '-' . $sequence,
        'sku' => 'RACE-' . strtoupper($token) . '-' . $sequence,
        'status' => Product::STATUS_ACTIVE,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $createdProducts[] = $id;

    return $id;
};

try {
    $storeId = (new StoreRepository())->create([
        'business_name' => 'Inventory race store ' . $token,
        'legal_name' => 'Inventory race legal ' . $token,
        'owner_name' => 'Race owner',
        'rut' => '1-9',
        'email' => $token . '@example.test',
        'phone' => '000000000',
        'status' => 'active',
        'onboarding_status' => 'draft',
        'approved_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $createdStores[] = $storeId;

    $normalProduct = $newProduct();
    $normalInventory = $inventoryService->create([
        'product_id' => $normalProduct,
        'minimarket_id' => $storeId,
        'price' => 1000,
        'stock' => 5,
        'status' => 'active',
    ]);
    raceSame(true, $normalInventory > 0, 'Create normal no inserto Inventory.');
    raceSame(0, (int) $wpdb->get_var('SELECT @@in_transaction'), 'Create normal dejo transaccion abierta.');

    $missing = raceException(
        fn () => $inventoryService->create([
            'product_id' => PHP_INT_MAX,
            'minimarket_id' => $storeId,
            'price' => 1000,
            'stock' => 1,
            'status' => 'active',
        ]),
        InventoryValidationException::class
    );
    raceSame('inventory_product_not_found', $missing->reason(), 'Product inexistente cambio contrato.');
    raceSame(0, (int) $wpdb->get_var('SELECT @@in_transaction'), 'Rollback propio dejo transaccion abierta.');

    $createWinsProduct = $newProduct();
    $createWorker = startRaceWorker('create', $createWinsProduct, $storeId, $now);
    $blockedDelete = raceException(
        fn () => $productService->delete($createWinsProduct, $now),
        ProductDeletionException::class
    );
    finishRaceWorker($createWorker);
    raceSame('product_delete_requires_retirement', $blockedDelete->errorCode(), 'DELETE no reinspecciono Inventory concurrente.');
    raceSame(true, $productService->find($createWinsProduct) !== null, 'DELETE elimino Product cuando CREATE gano.');
    raceSame(1, (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$inventoryTable} WHERE product_id = %d",
        $createWinsProduct
    )), 'CREATE ganador no dejo exactamente un Inventory.');
    raceSame(
        ProductReferenceInspection::RETIRE_REQUIRED,
        $productService->inspectReferences($createWinsProduct)->classification(),
        'Clasificacion final no exige retiro.'
    );

    $deleteWinsProduct = $newProduct();
    $deleteWorker = startRaceWorker('delete', $deleteWinsProduct, $storeId, $now);
    $losingCreate = raceException(
        fn () => $inventoryService->create([
            'product_id' => $deleteWinsProduct,
            'minimarket_id' => $storeId,
            'price' => 1000,
            'stock' => 5,
            'status' => 'active',
        ]),
        InventoryValidationException::class
    );
    finishRaceWorker($deleteWorker);
    raceSame('inventory_product_not_found', $losingCreate->reason(), 'CREATE perdedor cambio contrato referencial.');
    raceSame(null, $productService->find($deleteWinsProduct), 'DELETE ganador no elimino Product.');
    raceSame(0, (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$inventoryTable} WHERE product_id = %d",
        $deleteWinsProduct
    )), 'CREATE perdedor dejo Inventory huerfano.');

    $duplicateProduct = $newProduct();
    $duplicateWorker = startRaceWorker('create', $duplicateProduct, $storeId, $now);
    $duplicate = raceException(
        fn () => $inventoryService->create([
            'product_id' => $duplicateProduct,
            'minimarket_id' => $storeId,
            'price' => 2000,
            'stock' => 9,
            'status' => 'active',
        ]),
        InventoryValidationException::class
    );
    finishRaceWorker($duplicateWorker);
    raceSame('inventory_duplicate', $duplicate->reason(), 'Duplicado cambio contrato 422.');
    raceSame(1, (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$inventoryTable}"
        . ' WHERE product_id = %d AND minimarket_id = %d',
        $duplicateProduct,
        $storeId
    )), 'Dos CREATE concurrentes generaron duplicado.');

    raceSame(false, $wpdb->query('START TRANSACTION') === false, 'No inicio transaccion externa.');
    $savepointProduct = $newProduct();
    $invalidStore = raceException(
        fn () => $inventoryService->create([
            'product_id' => $savepointProduct,
            'minimarket_id' => PHP_INT_MAX,
            'price' => 1000,
            'stock' => 1,
            'status' => 'active',
        ]),
        InventoryValidationException::class
    );
    raceSame('inventory_store_not_found', $invalidStore->reason(), 'Store invalida cambio contrato.');
    raceSame(1, (int) $wpdb->get_var('SELECT @@in_transaction'), 'Rollback a savepoint cerro transaccion externa.');
    raceSame(1, (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$productsTable} WHERE id = %d",
        $savepointProduct
    )), 'Rollback a savepoint revirtio trabajo externo anterior.');
    $wpdb->query('ROLLBACK');
    array_pop($createdProducts);

    raceSame(0, (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$inventoryTable} i"
        . " LEFT JOIN {$productsTable} p ON p.id = i.product_id"
        . ' WHERE p.id IS NULL'
    ), 'La prueba detecto Inventory huerfano.');

    echo "PASS product-inventory-deletion-race-test\n";
} finally {
    $wpdb->query('ROLLBACK');

    foreach ($createdProducts as $productId) {
        $wpdb->delete($inventoryTable, ['product_id' => $productId]);
        $wpdb->delete($productsTable, ['id' => $productId]);
    }

    foreach ($createdStores as $storeId) {
        $wpdb->delete($storesTable, ['id' => $storeId]);
    }
}
