<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Cart\Service;

use InvalidArgumentException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Cart\Repository\CartRepository;

final class CartService
{
    public function __construct(private CartRepository $repository)
    {
    }

    /** @return array{id: int, created: bool} */
    public function addItem(
        array $owner,
        int $inventoryId,
        int $quantity
    ): array
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

        $totalQuantity = $quantity;

        if ($existing !== null) {
            $existingQuantity = $this->integerValue(
                $existing['quantity'] ?? null,
                'cantidad existente del carrito'
            );

            if ($existingQuantity > PHP_INT_MAX - $quantity) {
                throw new InvalidArgumentException(
                    'La cantidad total solicitada no es valida.'
                );
            }

            $totalQuantity += $existingQuantity;
        }

        $inventory = $this->validatedInventory(
            $inventoryId,
            $totalQuantity
        );

        if ($existing !== null) {
            $updated = $this->repository->updateQuantity(
                (int) $existing['id'],
                $totalQuantity,
                $inventory['price'],
                $sessionId,
                $userId
            );

            if (! $updated) {
                throw new RecordNotFoundException(
                    'El item del carrito no existe.'
                );
            }

            return [
                'id' => (int) $existing['id'],
                'created' => false,
            ];
        }

        $now = current_time('mysql');

        $id = $this->repository->create([
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

        return ['id' => $id, 'created' => true];
    }

    /** @return list<array<string, mixed>> */
    public function getCart(array $owner): array
    {
        [$sessionId, $userId] = $this->owner($owner);

        return $userId !== null
            ? $this->repository->findByUser($userId)
            : $this->repository->findBySession($sessionId);
    }

    /** @return array{items: list<array<string, mixed>>, total: string} */
    public function getPublicCart(array $owner): array
    {
        [$sessionId, $userId] = $this->owner($owner);
        $items = $userId !== null
            ? $this->repository->findPublicByUser($userId)
            : $this->repository->findPublicBySession($sessionId);
        $imageIds = [];
        $totalCents = 0;

        foreach ($items as $item) {
            $imageId = $this->nullableInteger(
                $item['product_image_id'] ?? null
            );

            if ($imageId !== null && $imageId > 0) {
                $imageIds[$imageId] = $imageId;
            }
        }

        if ($imageIds !== []) {
            _prime_post_caches(array_values($imageIds), false, true);
            update_meta_cache('post', array_values($imageIds));
        }

        foreach ($items as &$item) {
            $quantity = $this->cartQuantity($item['quantity'] ?? null);
            $unitCents = $this->decimalToCents(
                $item['unit_price_snapshot'] ?? null
            );
            $subtotalCents = $quantity !== null && $unitCents !== null
                && $unitCents <= intdiv(PHP_INT_MAX, $quantity)
                    ? $unitCents * $quantity
                    : null;
            $imageId = $this->nullableInteger(
                $item['product_image_id'] ?? null
            );
            $imageUrl = $imageId !== null && $imageId > 0
                ? wp_get_attachment_image_url($imageId, 'thumbnail')
                : false;

            $item['product_name'] = is_string($item['product_name'] ?? null)
                ? $item['product_name']
                : null;
            $item['product_image_id'] = $imageId;
            $item['product_image_url'] = is_string($imageUrl)
                ? $imageUrl
                : null;
            $item['minimarket_name'] = is_string(
                $item['minimarket_name'] ?? null
            ) ? $item['minimarket_name'] : null;
            $item['subtotal'] = $subtotalCents === null
                ? null
                : $this->formatCents($subtotalCents);

            if (
                $subtotalCents !== null
                && $totalCents <= PHP_INT_MAX - $subtotalCents
            ) {
                $totalCents += $subtotalCents;
            }
        }
        unset($item);

        return [
            'items' => $items,
            'total' => $this->formatCents($totalCents),
        ];
    }

    public function updateQuantity(
        array $owner,
        int $id,
        int $quantity
    ): bool {
        $this->assertPositive($id, 'id');
        $this->assertPositive($quantity, 'quantity');
        [$sessionId, $userId] = $this->owner($owner);

        $item = $this->repository->findOwnedItem(
            $id,
            $sessionId,
            $userId
        );

        if ($item === null) {
            throw new RecordNotFoundException(
                'El item del carrito no existe.'
            );
        }

        $inventoryId = $this->integerValue(
            $item['inventory_id'] ?? null,
            'inventory_id del carrito'
        );
        $inventory = $this->validatedInventory($inventoryId, $quantity);

        $updated = $this->repository->updateQuantity(
            $id,
            $quantity,
            $inventory['price'],
            $sessionId,
            $userId
        );

        if (! $updated) {
            throw new RecordNotFoundException(
                'El item del carrito no existe.'
            );
        }

        return true;
    }

    public function removeItem(array $owner, int $id): bool
    {
        $this->assertPositive($id, 'id');
        [$sessionId, $userId] = $this->owner($owner);

        $deleted = $this->repository->delete($id, $sessionId, $userId);

        if (! $deleted) {
            throw new RecordNotFoundException(
                'El item del carrito no existe.'
            );
        }

        return true;
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

    /** @return array{product_id: int, minimarket_id: int, price: string, stock: int} */
    private function validatedInventory(int $inventoryId, int $quantity): array
    {
        $inventory = $this->repository->findInventoryContext($inventoryId);

        if ($inventory === null) {
            throw new InvalidArgumentException(
                'El inventario solicitado no existe.'
            );
        }

        if (($inventory['inventory_status'] ?? null) !== 'active') {
            throw new InvalidArgumentException(
                'El inventario solicitado no esta activo.'
            );
        }

        $productId = $this->integerValue(
            $inventory['product_id'] ?? null,
            'producto del inventario'
        );
        $resolvedProductId = $this->nullableInteger(
            $inventory['resolved_product_id'] ?? null
        );

        if (
            $resolvedProductId !== $productId
            || ($inventory['product_status'] ?? null) !== 'active'
        ) {
            throw new InvalidArgumentException(
                'El producto asociado no esta disponible.'
            );
        }

        $minimarketId = $this->integerValue(
            $inventory['minimarket_id'] ?? null,
            'minimarket del inventario'
        );
        $resolvedMinimarketId = $this->nullableInteger(
            $inventory['resolved_minimarket_id'] ?? null
        );

        if (
            $resolvedMinimarketId !== $minimarketId
            || ($inventory['minimarket_status'] ?? null) !== 'active'
        ) {
            throw new InvalidArgumentException(
                'El minimarket asociado no esta disponible.'
            );
        }

        $price = $this->normalizedPrice(
            $inventory['inventory_price'] ?? null
        );

        $stock = $this->integerValue(
            $inventory['inventory_stock'] ?? null,
            'stock del inventario',
            false
        );

        if ($stock <= 0) {
            throw new InvalidArgumentException(
                'El inventario no tiene stock disponible.'
            );
        }

        if ($quantity > $stock) {
            throw new InvalidArgumentException(
                'La cantidad solicitada supera el stock disponible.'
            );
        }

        return [
            'product_id' => $productId,
            'minimarket_id' => $minimarketId,
            'price' => $price,
            'stock' => $stock,
        ];
    }

    private function integerValue(
        mixed $value,
        string $field,
        bool $positive = true
    ): int {
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            $validated = filter_var($value, FILTER_VALIDATE_INT);
            $value = $validated === false ? null : $validated;
        }

        if (! is_int($value) || ($positive && $value <= 0)) {
            throw new InvalidArgumentException(
                "El {$field} no es valido."
            );
        }

        return $value;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $validated = filter_var($value, FILTER_VALIDATE_INT);

            return $validated === false ? null : $validated;
        }

        return is_int($value) ? $value : null;
    }

    private function normalizedPrice(mixed $price): string
    {
        if (
            (! is_int($price) && ! is_float($price) && ! is_string($price))
            || ! is_numeric($price)
            || ! is_finite((float) $price)
            || (float) $price <= 0
        ) {
            throw new InvalidArgumentException(
                'El precio del inventario no es valido.'
            );
        }

        return number_format((float) $price, 2, '.', '');
    }

    private function cartQuantity(mixed $value): ?int
    {
        try {
            return $this->integerValue($value, 'quantity del carrito');
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function decimalToCents(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $normalized)) {
            return null;
        }

        [$whole, $decimal] = array_pad(explode('.', $normalized, 2), 2, '');
        $decimal = str_pad($decimal, 2, '0');

        if (strlen($whole) > strlen((string) intdiv(PHP_INT_MAX, 100))) {
            return null;
        }

        $cents = ((int) $whole * 100) + (int) $decimal;

        return $cents >= 0 ? $cents : null;
    }

    private function formatCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
