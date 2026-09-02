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
        private StoreRepository $storeRepository,
        private ?CheckoutFeeCalculator $feeCalculator = null
    ) {
    }

    public function validate(array $owner): array
    {
        $cartItems = $this->cartService->getCart($owner);
        $ownerScope = isset($owner['user_id']) && is_int($owner['user_id'])
            ? 'user|' . $owner['user_id']
            : 'session|' . (string) ($owner['session_id'] ?? '');

        if ($cartItems === []) {
            return $this->emptyCartResult();
        }

        $items = [];
        $errors = [];
        $validCount = 0;
        $totalCents = 0;
        $zoneId = (new \VeciAhorra\Modules\Sectorization\CurrentSector())->id();
        $allowedSectorStores = $zoneId > 0
            ? array_fill_keys(
                (new \VeciAhorra\Modules\Sectorization\ServiceZoneRepository())
                    ->allowedStoreIds($zoneId),
                true
            )
            : [];
        $eligibleStores = array_filter(
            $this->storeRepository->findActiveByIds(array_map(
                static fn (array $item): int =>
                    (int) ($item['minimarket_id'] ?? 0),
                $cartItems
            ))->toArray(),
            static fn (array $store): bool =>
                ($store['onboarding_status'] ?? null) === 'complete'
                && ! empty($store['approved_at'])
        );
        $activeMinimarketIds = [];
        $deliveryMinimarketIds = [];
        foreach ($eligibleStores as $store) {
            $storeId = (int) $store['id'];
            $activeMinimarketIds[$storeId] = true;
            if ((int) ($store['delivery_enabled'] ?? 1) === 1) {
                $deliveryMinimarketIds[$storeId] = true;
            }
        }

        foreach ($cartItems as $cartItem) {
            $result = $this->validateItem(
                $cartItem,
                $activeMinimarketIds,
                $allowedSectorStores,
                $deliveryMinimarketIds,
                $ownerScope
            );
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

        $deliveryItemsEligible = true;
        $items = array_map(
            static function (array $item) use (&$deliveryItemsEligible): array {
                $deliveryItemsEligible = $deliveryItemsEligible && $item['_delivery_eligible'];
                unset($item['_subtotal_cents']);
                unset($item['_delivery_eligible']);

                return $item;
            },
            $items
        );
        $itemCount = count($items);
        $invalidCount = $itemCount - $validCount;

        $method = is_string($owner['fulfillment_method'] ?? null)
            ? $owner['fulfillment_method'] : FulfillmentPolicy::PICKUP;
        $zoneEligible = $zoneId > 0;
        $deliveryEligible = $invalidCount === 0 && $zoneEligible && $deliveryItemsEligible;
        $minimum = (new CheckoutFeeConfiguration())->current()['delivery_minimum_subtotal_clp'];
        $minimumEligible = intdiv($totalCents, 100) >= $minimum;
        $checkoutValid = $invalidCount === 0;
        if ($method === FulfillmentPolicy::DELIVERY && (! $deliveryEligible || ! $minimumEligible)) {
            $errors[] = $this->error('delivery_not_eligible', 'El carrito no cumple las condiciones de despacho.');
            $checkoutValid = false;
        }
        $financial = ($this->feeCalculator ?? new CheckoutFeeCalculator())->calculate(
            intdiv($totalCents, 100),
            $method === FulfillmentPolicy::DELIVERY && $deliveryEligible && $minimumEligible
                ? FulfillmentPolicy::DELIVERY : FulfillmentPolicy::PICKUP,
            $deliveryEligible
        );

        return [
            'valid' => $checkoutValid,
            'errors' => $errors,
            'items' => $items,
            'summary' => [
                'item_count' => $itemCount,
                'valid_item_count' => $validCount,
                'invalid_item_count' => $invalidCount,
                ...$financial,
            ],
        ];
    }

    /**
     * @param array<int, true> $activeMinimarketIds
     * @param array<int, true> $allowedSectorStores
     * @param array<int, true> $deliveryMinimarketIds
     */
    private function validateItem(
        array $cartItem,
        array $activeMinimarketIds,
        array $allowedSectorStores,
        array $deliveryMinimarketIds,
        string $ownerScope
    ): array
    {
        $id = (int) ($cartItem['id'] ?? 0);
        $inventoryId = (int) ($cartItem['inventory_id'] ?? 0);
        $productId = (int) ($cartItem['product_id'] ?? 0);
        $minimarketId = (int) ($cartItem['minimarket_id'] ?? 0);
        $quantity = (int) ($cartItem['quantity'] ?? 0);
        $snapshot = $cartItem['unit_price_snapshot'] ?? null;
        $errors = [];
        if (! isset($allowedSectorStores[$minimarketId])) {
            $errors[] = $this->error('inventory_out_of_sector', 'La oferta no está disponible en el sector actual.');
        }
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
        $deliveryEligible = isset($allowedSectorStores[$minimarketId], $deliveryMinimarketIds[$minimarketId])
            && (int) ($inventory['delivery_enabled'] ?? 1) === 1
            && (int) ($product?->delivery_enabled ?? 1) === 1;

        return [
            'id' => $id,
            'inventory_id' => $inventoryId,
            'product_id' => $productId,
            'minimarket_id' => $minimarketId,
            'offer_group' => hash_hmac(
                'sha256',
                $ownerScope . '|cart-store|' . $minimarketId,
                wp_salt('auth')
            ),
            'quantity' => $quantity,
            'unit_price_snapshot' => $snapshotCents === null
                ? $snapshot
                : $this->formatCents($snapshotCents),
            'subtotal' => $this->formatCents($subtotalCents),
            'valid' => $errors === [],
            'errors' => $errors,
            '_subtotal_cents' => $subtotalCents,
            '_delivery_eligible' => $deliveryEligible,
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
                'product_subtotal' => '0.00',
                'platform_fee' => '0.00',
                'delivery_fee' => '0.00',
                'currency' => 'CLP',
                'fulfillment_method' => FulfillmentPolicy::PICKUP,
                'delivery_eligible' => false,
                'delivery_minimum_subtotal' => (new CheckoutFeeConfiguration())->current()['delivery_minimum_subtotal_clp'] . '.00',
                'fee_policy_version' => CheckoutFeeConfiguration::POLICY_VERSION,
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
