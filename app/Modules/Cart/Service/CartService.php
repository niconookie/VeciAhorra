<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Cart\Service;

use InvalidArgumentException;
use VeciAhorra\Modules\Cart\Repository\CartRepository;

final class CartService
{
    public function __construct(private CartRepository $repository)
    {
    }

    public function addItem(array $owner, int $inventoryId, int $quantity): int
    {
        $this->assertPositive($inventoryId, 'inventory_id');
        $this->assertPositive($quantity, 'quantity');
        [$sessionId, $userId] = $this->owner($owner);
        $existing = $userId !== null
            ? $this->repository->findItemByInventoryForUser(
                $userId,
                $inventoryId
            )
            : $this->repository->findItemByInventoryForSession(
                $sessionId,
                $inventoryId
            );

        if ($existing !== null) {
            $this->repository->incrementQuantity(
                (int) $existing['id'],
                $quantity
            );

            return (int) $existing['id'];
        }

        $inventory = $this->repository->findInventorySnapshot($inventoryId);

        if ($inventory === null) {
            throw new InvalidArgumentException(
                'El inventario solicitado no existe.'
            );
        }

        $now = current_time('mysql');

        return $this->repository->create([
            'session_id' => $sessionId,
            'user_id' => $userId,
            'inventory_id' => $inventoryId,
            'product_id' => (int) $inventory['product_id'],
            'minimarket_id' => (int) $inventory['minimarket_id'],
            'quantity' => $quantity,
            'unit_price_snapshot' => $inventory['price'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function getCart(array $owner): array
    {
        [$sessionId, $userId] = $this->owner($owner);

        return $userId !== null
            ? $this->repository->findByUser($userId)
            : $this->repository->findBySession($sessionId);
    }

    public function updateQuantity(
        array $owner,
        int $id,
        int $quantity
    ): bool {
        $this->assertPositive($id, 'id');
        $this->assertPositive($quantity, 'quantity');
        [$sessionId, $userId] = $this->owner($owner);

        return $this->repository->updateQuantity(
            $id,
            $quantity,
            $sessionId,
            $userId
        );
    }

    public function removeItem(array $owner, int $id): bool
    {
        $this->assertPositive($id, 'id');
        [$sessionId, $userId] = $this->owner($owner);

        return $this->repository->delete($id, $sessionId, $userId);
    }

    public function clearCart(array $owner): int
    {
        [$sessionId, $userId] = $this->owner($owner);

        return $this->repository->clear($sessionId, $userId);
    }

    /** @return array{0: ?string, 1: ?int} */
    private function owner(array $owner): array
    {
        $userId = $owner['user_id'] ?? null;

        if (is_int($userId) && $userId > 0) {
            return [null, $userId];
        }

        $sessionId = $owner['session_id'] ?? null;

        if (is_string($sessionId) && trim($sessionId) !== '') {
            return [trim($sessionId), null];
        }

        throw new InvalidArgumentException(
            'El carrito requiere session_id o user_id.'
        );
    }

    private function assertPositive(int $value, string $field): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(
                "El campo {$field} debe ser un entero positivo."
            );
        }
    }
}
