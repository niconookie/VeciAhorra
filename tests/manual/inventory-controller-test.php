<?php

declare(strict_types=1);

use VeciAhorra\Modules\Inventory\Controllers\InventoryController;
use VeciAhorra\Modules\Inventory\Services\InventoryReferenceValidator;
use VeciAhorra\Modules\Inventory\Services\InventoryService;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertControllerTrue(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertControllerSame(mixed $expected, mixed $actual): void
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

$controller = new InventoryController(new InventoryService());
$transactionStarted = $wpdb->query('START TRANSACTION');

assertControllerTrue(
    $transactionStarted !== false,
    'No fue posible iniciar la transaccion.'
);

try {
    $now = current_time('mysql');
    $suffix = bin2hex(random_bytes(6));
    $productId = (new ProductRepository())->create([
        'name' => 'Inventory controller ' . $suffix,
        'slug' => 'inventory-controller-' . $suffix,
        'status' => Product::STATUS_ACTIVE,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $minimarketId = (new StoreRepository())->create([
        'business_name' => 'Inventory controller ' . $suffix,
        'legal_name' => 'Inventory controller legal ' . $suffix,
        'owner_name' => 'Inventory controller owner',
        'rut' => '1-9',
        'email' => $suffix . '@example.test',
        'phone' => '000000000',
        'status' => 'active',
        'onboarding_status' => 'draft',
        'approved_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $create = $controller->create([
        'product_id' => $productId,
        'minimarket_id' => $minimarketId,
        'price' => 1500.0,
        'stock' => 9,
        'status' => 'active',
    ]);

    assertControllerSame(true, $create['success'] ?? null);
    $id = (int) ($create['data']['id'] ?? 0);
    assertControllerTrue($id > 0, 'Create no retorno ID valido.');

    $duplicate = $controller->create([
        'product_id' => $productId,
        'minimarket_id' => $minimarketId,
        'price' => 1600.0,
        'stock' => 1,
        'status' => 'active',
    ]);
    assertControllerSame(false, $duplicate['success'] ?? null);
    assertControllerSame(
        'validation_error',
        $duplicate['error']['code'] ?? null
    );

    $show = $controller->show($id);
    assertControllerSame(true, $show['success'] ?? null);
    assertControllerSame($id, (int) $show['data']['id']);

    $missing = $controller->show(PHP_INT_MAX);
    assertControllerSame(false, $missing['success'] ?? null);
    assertControllerSame(
        'inventory_not_found',
        $missing['error']['code'] ?? null
    );

    $index = $controller->index([
        'page' => 1,
        'per_page' => 10,
        'product_id' => $productId,
        'minimarket_id' => null,
        'status' => null,
        'search' => null,
    ]);
    assertControllerSame(true, $index['success'] ?? null);
    assertControllerTrue(is_array($index['data']), 'Index no retorno array.');
    assertControllerSame(1, count($index['data']));
    assertControllerSame(1, $index['meta']['total']);
    assertControllerSame(1, $index['meta']['total_pages']);

    $update = $controller->update($id, [
        'price' => 1700.0,
        'stock' => 7,
    ]);
    assertControllerSame(true, $update['success'] ?? null);
    assertControllerSame(true, $update['data']['updated'] ?? null);

    $price = $controller->updatePrice($id, ['price' => 1800.0]);
    assertControllerSame(true, $price['success'] ?? null);
    assertControllerSame(1800.0, $price['data']['price'] ?? null);

    $stock = $controller->updateStock($id, ['stock' => 5]);
    assertControllerSame(true, $stock['success'] ?? null);
    assertControllerSame(5, $stock['data']['stock'] ?? null);

    $status = $controller->changeStatus($id, ['status' => 'inactive']);
    assertControllerSame(true, $status['success'] ?? null);
    assertControllerSame('inactive', $status['data']['status'] ?? null);

    $updated = $controller->show($id)['data'];
    assertControllerSame('1800.00', $updated['price']);
    assertControllerSame(5, (int) $updated['stock']);
    assertControllerSame('inactive', $updated['status']);

    $invalid = $controller->changeStatus($id, ['status' => 'draft']);
    assertControllerSame(false, $invalid['success'] ?? null);
    assertControllerSame(
        'validation_error',
        $invalid['error']['code'] ?? null
    );

    $originalPrefix = $wpdb->prefix;
    $wpdb->suppress_errors(true);

    try {
        $wpdb->prefix = 'missing_inventory_controller_' . uniqid() . '_';
        $availableReferences = new InventoryReferenceValidator(
            static fn (int $id): object => (object) ['status' => 'active'],
            static fn (int $id): object => (object) ['status' => 'active']
        );
        $failingController = new InventoryController(new InventoryService(
            null,
            $availableReferences
        ));
        $persistence = $failingController->create([
            'product_id' => $productId + 1,
            'minimarket_id' => $minimarketId + 1,
            'price' => 1.0,
            'stock' => 0,
            'status' => 'active',
        ]);
    } finally {
        $wpdb->prefix = $originalPrefix;
        $wpdb->suppress_errors(false);
    }

    assertControllerSame(false, $persistence['success'] ?? null);
    assertControllerSame(
        'persistence_error',
        $persistence['error']['code'] ?? null
    );

    $delete = $controller->delete($id);
    assertControllerSame(true, $delete['success'] ?? null);
    assertControllerSame(true, $delete['data']['deleted'] ?? null);
    assertControllerSame(
        'inventory_not_found',
        $controller->delete($id)['error']['code'] ?? null
    );

    $controllerFile = file_get_contents(
        dirname(__DIR__, 2)
        . '/app/Modules/Inventory/Controllers/InventoryController.php'
    );
    assertControllerTrue(
        ! str_contains($controllerFile, '$wpdb'),
        'InventoryController contiene acceso directo a $wpdb.'
    );
    assertControllerTrue(
        preg_match('/\b(SELECT|INSERT INTO|DELETE FROM)\b/i', $controllerFile)
        !== 1,
        'InventoryController contiene SQL.'
    );

    echo "PASS inventory-controller-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
