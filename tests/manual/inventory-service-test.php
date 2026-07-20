<?php

declare(strict_types=1);

use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Inventory\Services\InventoryReferenceValidator;
use VeciAhorra\Modules\Inventory\Services\InventoryService;
use VeciAhorra\Modules\Inventory\Exceptions\InventoryDuplicateException;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertServiceTrue(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertServiceSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertServiceInvalid(callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException('Se esperaba InvalidArgumentException.');
}

global $wpdb;

$service = new InventoryService();
$transactionStarted = $wpdb->query('START TRANSACTION');

assertServiceTrue(
    $transactionStarted !== false,
    'No fue posible iniciar la transaccion.'
);

try {
    $now = current_time('mysql');
    $suffix = bin2hex(random_bytes(6));
    $productId = (new ProductRepository())->create([
        'name' => 'Inventory service ' . $suffix,
        'slug' => 'inventory-service-' . $suffix,
        'status' => Product::STATUS_ACTIVE,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $minimarketId = (new StoreRepository())->create([
        'business_name' => 'Inventory service ' . $suffix,
        'legal_name' => 'Inventory service legal ' . $suffix,
        'owner_name' => 'Inventory service owner',
        'rut' => '1-9',
        'email' => $suffix . '@example.test',
        'phone' => '000000000',
        'status' => 'active',
        'onboarding_status' => 'draft',
        'approved_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $id = $service->create([
        'product_id' => $productId,
        'minimarket_id' => $minimarketId,
        'price' => 1000.0,
        'stock' => 10,
        'status' => 'active',
    ]);

    assertServiceTrue($id > 0, 'Create no retorno un ID valido.');
    assertServiceInvalid(fn () => $service->create([
        'product_id' => $productId,
        'minimarket_id' => $minimarketId,
        'price' => 1100.0,
        'stock' => 2,
        'status' => 'active',
    ]));

    $errorsSuppressed = $wpdb->suppress_errors(true);

    try {
        (new InventoryRepository())->create([
            'product_id' => $productId,
            'minimarket_id' => $minimarketId,
            'price' => 1100.0,
            'stock' => 2,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        throw new RuntimeException(
            'Se esperaba InventoryDuplicateException desde el UNIQUE.'
        );
    } catch (InventoryDuplicateException) {
    } finally {
        $wpdb->suppress_errors($errorsSuppressed);
    }

    $found = $service->find($id);
    assertServiceTrue(is_array($found), 'Find no retorno el registro.');
    assertServiceSame($productId, (int) $found['product_id']);

    $page = $service->paginate([
        'page' => 1,
        'per_page' => 10,
        'product_id' => $productId,
    ]);
    assertServiceTrue(is_array($page), 'Paginate no retorno array.');
    assertServiceSame(1, count($page));
    assertServiceTrue(
        is_int($service->count(['product_id' => $productId])),
        'Count no retorno entero.'
    );

    assertServiceTrue(
        $service->updatePrice($id, 1250.50),
        'UpdatePrice no modifico el registro.'
    );
    $afterPrice = $service->find($id);
    assertServiceSame('1250.50', $afterPrice['price']);
    assertServiceSame(10, (int) $afterPrice['stock']);
    assertServiceSame('active', $afterPrice['status']);

    assertServiceTrue(
        $service->updateStock($id, 4),
        'UpdateStock no modifico el registro.'
    );
    $afterStock = $service->find($id);
    assertServiceSame('1250.50', $afterStock['price']);
    assertServiceSame(4, (int) $afterStock['stock']);
    assertServiceSame('active', $afterStock['status']);

    assertServiceTrue(
        $service->changeStatus($id, 'inactive'),
        'ChangeStatus no modifico el registro.'
    );
    $afterStatus = $service->find($id);
    assertServiceSame('1250.50', $afterStatus['price']);
    assertServiceSame(4, (int) $afterStatus['stock']);
    assertServiceSame('inactive', $afterStatus['status']);

    assertServiceTrue(
        $service->update($id, [
            'price' => 1300.0,
            'stock' => 7,
        ]),
        'Update parcial no modifico el registro.'
    );
    $afterUpdate = $service->find($id);
    assertServiceSame('1300.00', $afterUpdate['price']);
    assertServiceSame(7, (int) $afterUpdate['stock']);
    assertServiceSame('inactive', $afterUpdate['status']);

    assertServiceInvalid(fn () => $service->update($id, []));
    assertServiceInvalid(fn () => $service->updatePrice($id, -1.0));
    assertServiceInvalid(fn () => $service->updateStock($id, -1));
    assertServiceInvalid(fn () => $service->changeStatus($id, 'draft'));

    $originalPrefix = $wpdb->prefix;
    $wpdb->suppress_errors(true);

    try {
        $wpdb->prefix = 'missing_inventory_' . uniqid() . '_';

        $availableReferences = new InventoryReferenceValidator(
            static fn (int $id): object => (object) ['status' => 'active'],
            static fn (int $id): object => (object) ['status' => 'active']
        );
        $failingService = new InventoryService(
            null,
            $availableReferences
        );

        try {
            $failingService->create([
                'product_id' => $productId + 1,
                'minimarket_id' => $minimarketId + 1,
                'price' => 1.0,
                'stock' => 0,
                'status' => 'active',
            ]);
            throw new RuntimeException(
                'Se esperaba un error de dominio de persistencia.'
            );
        } catch (RuntimeException $exception) {
            assertServiceTrue(
                ! $exception instanceof PersistenceException,
                'El Service expuso PersistenceException directamente.'
            );
            assertServiceTrue(
                $exception->getPrevious() instanceof PersistenceException,
                'El error traducido no conserva la causa del Repository.'
            );
            assertServiceSame(
                'No fue posible crear el inventario.',
                $exception->getMessage()
            );
        }
    } finally {
        $wpdb->prefix = $originalPrefix;
        $wpdb->suppress_errors(false);
    }

    assertServiceTrue($service->delete($id), 'Delete no elimino el registro.');
    assertServiceSame(null, $service->find($id));

    try {
        $service->delete($id);
        throw new RuntimeException(
            'Se esperaba RecordNotFoundException.'
        );
    } catch (RecordNotFoundException) {
    }

    $serviceFile = file_get_contents(
        dirname(__DIR__, 2)
        . '/app/Modules/Inventory/Services/InventoryService.php'
    );
    assertServiceTrue(
        ! str_contains($serviceFile, '$wpdb'),
        'InventoryService contiene acceso directo a $wpdb.'
    );
    assertServiceTrue(
        preg_match('/\b(SELECT|INSERT INTO|DELETE FROM)\b/i', $serviceFile)
        !== 1,
        'InventoryService contiene SQL.'
    );

    echo "PASS inventory-service-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
