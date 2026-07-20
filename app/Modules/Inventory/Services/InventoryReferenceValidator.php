<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Services;

use Closure;
use VeciAhorra\Modules\Inventory\Exceptions\InventoryValidationException;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Services\ProductService;
use VeciAhorra\Modules\Stores\Services\StoreService;

/**
 * Valida las referencias lógicas requeridas por una oferta de Inventory.
 */
final class InventoryReferenceValidator
{
    private const STORE_STATUSES = [
        'pending',
        'active',
        'inactive',
        'rejected',
    ];

    private Closure $productFinder;

    private Closure $storeFinder;

    public function __construct(
        ?Closure $productFinder = null,
        ?Closure $storeFinder = null
    ) {
        $productService = $productFinder === null
            ? new ProductService()
            : null;
        $storeService = $storeFinder === null
            ? new StoreService()
            : null;

        $this->productFinder = $productFinder
            ?? static fn (int $id): mixed => $productService->find($id);
        $this->storeFinder = $storeFinder
            ?? static fn (int $id): mixed => $storeService->find($id);
    }

    /**
     * @return array{product: object, store: object}
     */
    public function validate(int $productId, int $storeId): array
    {
        $product = ($this->productFinder)($productId);

        if ($product === null) {
            throw new InventoryValidationException(
                'El producto seleccionado no existe.',
                'product_id',
                'inventory_product_not_found'
            );
        }

        if (! in_array($product->status, Product::allowedStatuses(), true)) {
            throw new InventoryValidationException(
                'El producto tiene un estado no compatible con esta operacion.',
                'product_id',
                'inventory_product_incompatible'
            );
        }

        $store = ($this->storeFinder)($storeId);

        if ($store === null) {
            throw new InventoryValidationException(
                'El minimarket seleccionado no existe.',
                'store_id',
                'inventory_store_not_found'
            );
        }

        if (! in_array($store->status, self::STORE_STATUSES, true)) {
            throw new InventoryValidationException(
                'El minimarket tiene un estado no compatible con esta operacion.',
                'store_id',
                'inventory_store_incompatible'
            );
        }

        return [
            'product' => $product,
            'store' => $store,
        ];
    }
}
