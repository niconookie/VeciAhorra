<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Services;

use InvalidArgumentException;
use RuntimeException;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Inventory\Contracts\InventoryRepositoryInterface;
use VeciAhorra\Modules\Inventory\Exceptions\InventoryDuplicateException;
use VeciAhorra\Modules\Inventory\Exceptions\InventoryValidationException;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;

/**
 * Casos de uso del inventario del marketplace.
 */
final class InventoryService
{
    private const ALLOWED_STATUSES = [
        'active',
        'inactive',
    ];

    private InventoryRepositoryInterface $repository;

    private InventoryReferenceValidator $referenceValidator;

    public function __construct(
        ?InventoryRepositoryInterface $repository = null,
        ?InventoryReferenceValidator $referenceValidator = null
    ) {
        $this->repository = $repository
            ?? new InventoryRepository();
        $this->referenceValidator = $referenceValidator
            ?? new InventoryReferenceValidator();
    }

    public function paginate(array $filters): array
    {
        return $this->repository->paginate($filters);
    }

    public function count(array $filters): int
    {
        return $this->repository->count($filters);
    }

    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    public function create(array $data): int
    {
        $productId = (int) ($data['product_id'] ?? 0);
        $minimarketId = (int) ($data['minimarket_id'] ?? 0);
        $price = $data['price'] ?? null;
        $stock = $data['stock'] ?? 0;
        $status = $data['status'] ?? 'active';

        $this->assertPositiveId(
            $productId,
            'product_id',
            'El identificador de producto debe ser positivo.',
            'inventory_invalid_product_id'
        );
        $this->assertPositiveId(
            $minimarketId,
            'store_id',
            'El identificador de minimarket debe ser positivo.',
            'inventory_invalid_store_id'
        );
        $this->referenceValidator->validate($productId, $minimarketId);
        $this->assertPrice($price);
        $this->assertStock($stock);
        $this->assertAllowedStatus($status);

        try {
            $existing = $this->repository->findByProductAndMinimarket(
                $productId,
                $minimarketId
            );
        } catch (PersistenceException $exception) {
            throw new RuntimeException(
                'No fue posible crear el inventario.',
                0,
                $exception
            );
        }

        if ($existing !== null) {
            throw $this->duplicateException();
        }

        $now = current_time('mysql');
        $payload = [
            'product_id' => $productId,
            'minimarket_id' => $minimarketId,
            'price' => (float) $price,
            'stock' => (int) $stock,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        try {
            return $this->repository->create($payload);
        } catch (InventoryDuplicateException $exception) {
            throw $this->duplicateException($exception);
        } catch (PersistenceException $exception) {
            throw new RuntimeException(
                'No fue posible crear el inventario.',
                0,
                $exception
            );
        }
    }

    public function updatePrice(int $id, float $price): bool
    {
        return $this->update($id, ['price' => $price]);
    }

    public function updateStock(int $id, int $stock): bool
    {
        return $this->update($id, ['stock' => $stock]);
    }

    public function changeStatus(int $id, string $status): bool
    {
        return $this->update($id, ['status' => $status]);
    }

    public function update(int $id, array $data): bool
    {
        $inventory = $this->requireInventory($id);
        $payload = [];

        foreach (['product_id', 'minimarket_id'] as $field) {
            if (array_key_exists($field, $data)) {
                throw new InvalidArgumentException(
                    "El campo {$field} no se puede actualizar."
                );
            }
        }

        $this->referenceValidator->validate(
            (int) $inventory['product_id'],
            (int) $inventory['minimarket_id']
        );

        if (array_key_exists('price', $data)) {
            $this->assertPrice($data['price']);
            $payload['price'] = (float) $data['price'];
        }

        if (array_key_exists('stock', $data)) {
            $this->assertStock($data['stock']);
            $payload['stock'] = (int) $data['stock'];
        }

        if (array_key_exists('status', $data)) {
            $this->assertAllowedStatus($data['status']);
            $payload['status'] = $data['status'];
        }

        if ($payload === []) {
            throw new InvalidArgumentException(
                'La actualizacion requiere al menos un campo permitido.'
            );
        }

        $payload['updated_at'] = current_time('mysql');

        try {
            return $this->repository->update($id, $payload);
        } catch (PersistenceException $exception) {
            throw new RuntimeException(
                'No fue posible actualizar el inventario.',
                0,
                $exception
            );
        }
    }

    public function delete(int $id): bool
    {
        $this->requireInventory($id);

        try {
            return $this->repository->delete($id);
        } catch (PersistenceException $exception) {
            throw new RuntimeException(
                'No fue posible eliminar el inventario.',
                0,
                $exception
            );
        }
    }

    private function requireInventory(int $id): array
    {
        $inventory = $this->repository->find($id);

        if ($inventory === null) {
            throw new RecordNotFoundException(
                'El inventario solicitado no existe.'
            );
        }

        return $inventory;
    }

    private function assertPositiveId(
        int $id,
        string $field,
        string $message,
        string $reason
    ): void {
        if ($id > 0) {
            return;
        }

        throw new InventoryValidationException(
            $message,
            $field,
            $reason
        );
    }

    private function assertPrice(mixed $price): void
    {
        if (
            ! is_int($price)
            && ! is_float($price)
        ) {
            throw new InvalidArgumentException(
                'El precio del inventario debe ser numerico.'
            );
        }

        if (! is_finite((float) $price) || $price < 0) {
            throw new InvalidArgumentException(
                'El precio del inventario debe ser mayor o igual a 0.'
            );
        }
    }

    private function assertStock(mixed $stock): void
    {
        if (! is_int($stock) || $stock < 0) {
            throw new InvalidArgumentException(
                'El stock del inventario debe ser un entero mayor o igual a 0.'
            );
        }
    }

    private function assertAllowedStatus(mixed $status): void
    {
        if (
            ! is_string($status)
            || ! in_array($status, self::ALLOWED_STATUSES, true)
        ) {
            throw new InvalidArgumentException(
                'El estado del inventario no es valido.'
            );
        }
    }

    private function duplicateException(
        ?\Throwable $previous = null
    ): InventoryValidationException {
        return new InventoryValidationException(
            'Ya existe una oferta para este producto y minimarket.',
            'store_id',
            'inventory_duplicate',
            $previous
        );
    }
}
