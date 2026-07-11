<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Service;

use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Inventory\Services\InventoryService;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Services\ProductService;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

/**
 * Valida un carrito para checkout sin producir efectos laterales.
 */
final class CheckoutValidationService
{
    public function __construct(
        private CartService $cartService,
        private InventoryService $inventoryService,
        private ProductService $productService,
        private StoreRepository $storeRepository
    ) {
    }

    public function validate(array $owner): array
    {
        $cartItems = $this->cartService->getCart($owner);

        if ($cartItems === []) {
            return $this->emptyCartResult();
        }

        $items = [];
        $errors = [];
        $validCount = 0;
        $totalCents = 0;
        $activeMinimarketIds = array_fill_keys(array_map(
            static fn (array $store): int => (int) $store['id'],
            $this->storeRepository->findActiveByIds(array_map(
                static fn (array $item): int =>
                    (int) ($item['minimarket_id'] ?? 0),
                $cartItems
            ))->toArray()
        ), true);

        foreach ($cartItems as $cartItem) {
            $result = $this->validateItem($cartItem, $activeMinimarketIds);
            $items[] = $result;

            if ($result['valid']) {
                $validCount++;
                $totalCents += $result['_subtotal_cents'];
            } else {
                foreach ($result['errors'] as $error) {
                    $errors[] = [
                        'cart_item_id' => $result['id'],
                        ...$error,
                    ];
                }
            }
        }

        $items = array_map(
            static function (array $item): array {
                unset($item['_subtotal_cents']);

                return $item;
            },
            $items
        );
        $itemCount = count($items);
        $invalidCount = $itemCount - $validCount;

        return [
            'valid' => $invalidCount === 0,
            'errors' => $errors,
            'items' => $items,
            'summary' => [
                'item_count' => $itemCount,
                'valid_item_count' => $validCount,
                'invalid_item_count' => $invalidCount,
                'total' => $this->formatCents($totalCents),
            ],
        ];
    }

    /** @param array<int, true> $activeMinimarketIds */
    private function validateItem(
        array $cartItem,
        array $activeMinimarketIds
    ): array
    {
        $id = (int) ($cartItem['id'] ?? 0);
        $inventoryId = (int) ($cartItem['inventory_id'] ?? 0);
        $productId = (int) ($cartItem['product_id'] ?? 0);
        $minimarketId = (int) ($cartItem['minimarket_id'] ?? 0);
        $quantity = (int) ($cartItem['quantity'] ?? 0);
        $snapshot = $cartItem['unit_price_snapshot'] ?? null;
        $errors = [];
        $snapshotCents = $this->decimalToCents($snapshot);

        if ($inventoryId <= 0) {
            $errors[] = $this->error(
                'invalid_inventory_id',
                'El inventory_id debe ser positivo.'
            );
        }

        if ($quantity <= 0) {
            $errors[] = $this->error(
                'invalid_quantity',
                'La quantity debe ser mayor que 0.'
            );
        }

        if (
            $minimarketId <= 0
            || ! isset($activeMinimarketIds[$minimarketId])
        ) {
            $errors[] = $this->error(
                'minimarket_inactive',
                'El minimarket asociado no esta disponible.'
            );
        }

        if ($snapshotCents === null) {
            $errors[] = $this->error(
                'invalid_price_snapshot',
                'El precio snapshot no es valido.'
            );
        }

        $inventory = $inventoryId > 0
            ? $this->inventoryService->find($inventoryId)
            : null;

        if ($inventoryId > 0 && $inventory === null) {
            $errors[] = $this->error(
                'inventory_not_found',
                'El inventario no existe.'
            );
        }

        if ($inventory !== null) {
            if (($inventory['status'] ?? null) !== 'active') {
                $errors[] = $this->error(
                    'inventory_inactive',
                    'El inventario no esta activo.'
                );
            }

            if ((int) ($inventory['product_id'] ?? 0) !== $productId) {
                $errors[] = $this->error(
                    'inventory_product_mismatch',
                    'El inventario no corresponde al producto del carrito.'
                );
            }

            if (
                (int) ($inventory['minimarket_id'] ?? 0)
                    !== $minimarketId
            ) {
                $errors[] = $this->error(
                    'inventory_minimarket_mismatch',
                    'El inventario no corresponde al minimarket del carrito.'
                );
            }

            if (
                $quantity > 0
                && (int) ($inventory['stock'] ?? 0) < $quantity
            ) {
                $errors[] = $this->error(
                    'insufficient_stock',
                    'El inventario no tiene stock suficiente.'
                );
            }

            $currentPriceCents = $this->decimalToCents(
                $inventory['price'] ?? null
            );

            if (
                $snapshotCents !== null
                && (
                    $currentPriceCents === null
                    || $currentPriceCents !== $snapshotCents
                )
            ) {
                $errors[] = $this->error(
                    'price_changed',
                    'El precio actual difiere del snapshot del carrito.'
                );
            }
        }

        $product = $productId > 0
            ? $this->productService->find($productId)
            : null;

        if ($product === null) {
            $errors[] = $this->error(
                'product_not_found',
                'El producto asociado no existe.'
            );
        } elseif ($product->status !== Product::STATUS_ACTIVE) {
            $errors[] = $this->error(
                'product_inactive',
                'El producto asociado no esta activo.'
            );
        }

        $subtotalCents = $snapshotCents !== null && $quantity > 0
            ? $snapshotCents * $quantity
            : 0;

        return [
            'id' => $id,
            'inventory_id' => $inventoryId,
            'product_id' => $productId,
            'minimarket_id' => $minimarketId,
            'quantity' => $quantity,
            'unit_price_snapshot' => $snapshotCents === null
                ? $snapshot
                : $this->formatCents($snapshotCents),
            'subtotal' => $this->formatCents($subtotalCents),
            'valid' => $errors === [],
            'errors' => $errors,
            '_subtotal_cents' => $subtotalCents,
        ];
    }

    private function emptyCartResult(): array
    {
        $error = $this->error(
            'empty_cart',
            'El carrito esta vacio.'
        );

        return [
            'valid' => false,
            'errors' => [$error],
            'items' => [],
            'summary' => [
                'item_count' => 0,
                'valid_item_count' => 0,
                'invalid_item_count' => 0,
                'total' => '0.00',
            ],
        ];
    }

    /** @return array{code: string, message: string} */
    private function error(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    private function decimalToCents(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
            return null;
        }

        [$whole, $decimal] = array_pad(explode('.', $normalized, 2), 2, '');
        $decimal = str_pad($decimal, 2, '0');

        if (strlen($whole) > strlen((string) intdiv(PHP_INT_MAX, 100))) {
            return null;
        }

        return ((int) $whole * 100) + (int) $decimal;
    }

    private function formatCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
