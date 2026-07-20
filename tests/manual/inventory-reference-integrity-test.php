<?php

declare(strict_types=1);

use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Inventory\Contracts\InventoryRepositoryInterface;
use VeciAhorra\Modules\Inventory\Controllers\InventoryController;
use VeciAhorra\Modules\Inventory\Exceptions\InventoryDuplicateException;
use VeciAhorra\Modules\Inventory\Services\InventoryReferenceValidator;
use VeciAhorra\Modules\Inventory\Services\InventoryService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertReferenceSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertReferenceError(
    array $result,
    string $field,
    string $reason
): void {
    assertReferenceSame(false, $result['success'] ?? null);
    assertReferenceSame('validation_error', $result['error']['code'] ?? null);
    assertReferenceSame($field, $result['error']['details']['field'] ?? null);
    assertReferenceSame($reason, $result['error']['details']['reason'] ?? null);
}

final class InventoryReferenceFakeRepository implements InventoryRepositoryInterface
{
    public ?array $inventory = null;

    public ?array $pair = null;

    public string $createFailure = '';

    public array $lastUpdate = [];

    public function paginate(array $filters): array
    {
        return [];
    }

    public function count(array $filters): int
    {
        return 0;
    }

    public function find(int $id): ?array
    {
        return $this->inventory;
    }

    public function findByProductAndMinimarket(
        int $productId,
        int $minimarketId
    ): ?array {
        return $this->pair;
    }

    public function create(array $data): int
    {
        if ($this->createFailure === 'duplicate') {
            throw new InventoryDuplicateException('internal unique index');
        }

        if ($this->createFailure === 'persistence') {
            throw new PersistenceException('internal database detail');
        }

        return 321;
    }

    public function update(int $id, array $data): bool
    {
        $this->lastUpdate = $data;

        return true;
    }

    public function delete(int $id): bool
    {
        return true;
    }
}

$repository = new InventoryReferenceFakeRepository();
$product = (object) ['status' => 'inactive'];
$store = (object) [
    'status' => 'inactive',
    'onboarding_status' => 'draft',
    'approved_at' => null,
];
$validator = new InventoryReferenceValidator(
    static fn (int $id): ?object => $id === 10 ? $product : null,
    static fn (int $id): ?object => $id === 20 ? $store : null
);
$controller = new InventoryController(new InventoryService(
    $repository,
    $validator
));

$valid = $controller->create([
    'product_id' => 10,
    'minimarket_id' => 20,
    'price' => 0.0,
    'stock' => 0,
    'status' => 'active',
]);
assertReferenceSame(true, $valid['success'] ?? null);
assertReferenceSame(321, $valid['data']['id'] ?? null);

assertReferenceError(
    $controller->create([
        'product_id' => 999,
        'minimarket_id' => 20,
        'price' => 1.0,
        'stock' => 1,
        'status' => 'active',
    ]),
    'product_id',
    'inventory_product_not_found'
);
assertReferenceError(
    $controller->create([
        'product_id' => 10,
        'minimarket_id' => 999,
        'price' => 1.0,
        'stock' => 1,
        'status' => 'active',
    ]),
    'store_id',
    'inventory_store_not_found'
);
assertReferenceError(
    $controller->create([
        'product_id' => 0,
        'minimarket_id' => 20,
        'price' => 1.0,
        'stock' => 1,
        'status' => 'active',
    ]),
    'product_id',
    'inventory_invalid_product_id'
);
assertReferenceError(
    $controller->create([
        'product_id' => 10,
        'minimarket_id' => 0,
        'price' => 1.0,
        'stock' => 1,
        'status' => 'active',
    ]),
    'store_id',
    'inventory_invalid_store_id'
);

$repository->pair = ['id' => 99];
$ordinaryDuplicate = $controller->create([
    'product_id' => 10,
    'minimarket_id' => 20,
    'price' => 1.0,
    'stock' => 1,
    'status' => 'active',
]);
assertReferenceError(
    $ordinaryDuplicate,
    'store_id',
    'inventory_duplicate'
);

$repository->pair = null;
$repository->createFailure = 'duplicate';
$concurrentDuplicate = $controller->create([
    'product_id' => 10,
    'minimarket_id' => 20,
    'price' => 1.0,
    'stock' => 1,
    'status' => 'active',
]);
assertReferenceSame($ordinaryDuplicate, $concurrentDuplicate);

$repository->createFailure = '';
$repository->inventory = [
    'id' => 321,
    'product_id' => 10,
    'minimarket_id' => 20,
];
assertReferenceSame(true, $controller->update(321, [
    'price' => 2.0,
    'stock' => 3,
    'status' => 'inactive',
])['success'] ?? null);
assertReferenceSame(2.0, $repository->lastUpdate['price'] ?? null);
assertReferenceSame(3, $repository->lastUpdate['stock'] ?? null);
assertReferenceSame('inactive', $repository->lastUpdate['status'] ?? null);

$productFailure = new InventoryController(new InventoryService(
    new InventoryReferenceFakeRepository(),
    new InventoryReferenceValidator(
        static function (int $id): never {
            throw new RuntimeException('private product failure');
        },
        static fn (int $id): object => (object) ['status' => 'active']
    )
));
$productTechnical = $productFailure->create([
    'product_id' => 10,
    'minimarket_id' => 20,
    'price' => 1.0,
    'stock' => 1,
    'status' => 'active',
]);
assertReferenceSame('internal_error', $productTechnical['error']['code'] ?? null);
assertReferenceSame('Ocurrio un error interno.', $productTechnical['error']['message'] ?? null);

$storeFailure = new InventoryController(new InventoryService(
    new InventoryReferenceFakeRepository(),
    new InventoryReferenceValidator(
        static fn (int $id): object => (object) ['status' => 'active'],
        static function (int $id): never {
            throw new RuntimeException('private store failure');
        }
    )
));
$storeTechnical = $storeFailure->create([
    'product_id' => 10,
    'minimarket_id' => 20,
    'price' => 1.0,
    'stock' => 1,
    'status' => 'active',
]);
assertReferenceSame('internal_error', $storeTechnical['error']['code'] ?? null);
assertReferenceSame('Ocurrio un error interno.', $storeTechnical['error']['message'] ?? null);

$persistenceRepository = new InventoryReferenceFakeRepository();
$persistenceRepository->createFailure = 'persistence';
$persistenceController = new InventoryController(new InventoryService(
    $persistenceRepository,
    new InventoryReferenceValidator(
        static fn (int $id): object => (object) ['status' => 'active'],
        static fn (int $id): object => (object) ['status' => 'active']
    )
));
$technical = $persistenceController->create([
    'product_id' => 10,
    'minimarket_id' => 20,
    'price' => 1.0,
    'stock' => 1,
    'status' => 'active',
]);
assertReferenceSame('persistence_error', $technical['error']['code'] ?? null);
assertReferenceSame('No fue posible completar la operacion.', $technical['error']['message'] ?? null);

$validatorFile = file_get_contents(
    dirname(__DIR__, 2)
    . '/app/Modules/Inventory/Services/InventoryReferenceValidator.php'
);
assertReferenceSame(false, str_contains($validatorFile, '$wpdb'));
assertReferenceSame(
    0,
    preg_match('/\b(SELECT|INSERT INTO|UPDATE .* SET|DELETE FROM)\b/i', $validatorFile)
);
assertReferenceSame(false, str_contains($validatorFile, 'onboarding_status'));
assertReferenceSame(false, str_contains($validatorFile, 'approved_at'));

echo "PASS inventory-reference-integrity-test\n";
