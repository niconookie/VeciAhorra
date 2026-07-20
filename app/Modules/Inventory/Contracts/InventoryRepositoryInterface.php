<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Contracts;

interface InventoryRepositoryInterface
{
    public function paginate(array $filters): array;

    public function count(array $filters): int;

    public function find(int $id): ?array;

    public function findByProductAndMinimarket(
        int $productId,
        int $minimarketId
    ): ?array;

    public function create(array $data): int;

    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;
}
