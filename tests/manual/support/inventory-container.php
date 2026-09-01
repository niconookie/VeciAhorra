<?php

declare(strict_types=1);

use VeciAhorra\Core\Container;
use VeciAhorra\Modules\Inventory\Contracts\InventoryRepositoryInterface;
use VeciAhorra\Modules\Inventory\Services\InventoryReferenceValidator;
use VeciAhorra\Modules\Inventory\Services\InventoryService;

final class ManualTestInventoryRepository implements InventoryRepositoryInterface
{
    public function __construct(private InventoryRepositoryInterface $delegate) {}

    public function paginate(array $filters): array { return $this->delegate->paginate($filters); }
    public function count(array $filters): int { return $this->delegate->count($filters); }
    public function find(int $id): ?array { return $this->delegate->find($id); }
    public function findByProductAndMinimarket(int $productId, int $minimarketId): ?array
    {
        return $this->delegate->findByProductAndMinimarket($productId, $minimarketId);
    }
    public function create(array $data): int { return $this->delegate->create($data); }
    public function update(int $id, array $data): bool { return $this->delegate->update($id, $data); }
    public function delete(int $id): bool { return $this->delegate->delete($id); }
}

function manualTestInventoryContainer(
    InventoryRepositoryInterface $delegate
): Container {
    $inventory = new ManualTestInventoryRepository($delegate);
    $container = new Container();
    $container->bind(
        InventoryRepositoryInterface::class,
        static fn (): InventoryRepositoryInterface => $inventory
    );
    $container->bind(
        InventoryService::class,
        static fn (): InventoryService => new InventoryService(
            $inventory,
            new InventoryReferenceValidator()
        )
    );

    return $container;
}
