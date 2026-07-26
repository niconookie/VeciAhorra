<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Services;

use VeciAhorra\Modules\Inventory\Domain\OfferAvailabilityPolicy;
use VeciAhorra\Modules\Inventory\Repositories\InventoryAdminReadRepository;
use VeciAhorra\Modules\Inventory\Repositories\InventoryReferenceInspector;
use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract;
use WP_Post;

/**
 * Compone DTO administrativos sin transferir autoridades a Inventory.
 */
final class InventoryAdminReadService
{
    public function __construct(
        private InventoryAdminReadRepository $repository,
        private InventoryReferenceInspector $inspector,
        private OfferAvailabilityPolicy $availability,
        private StoreLifecycleContract $storeLifecycle
    ) {
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function paginate(array $filters): array
    {
        $rows = $this->repository->paginate($filters);
        $references = $rows === []
            ? []
            : $this->inspector->inspectMany(array_map(
                static fn (array $row): int =>
                    (int) ($row['inventory_id'] ?? 0),
                $rows
            ));
        $items = [];

        foreach ($rows as $row) {
            $id = (int) $row['inventory_id'];
            $items[] = $this->listItem(
                $row,
                $references[$id] ?? null
            );
        }

        return [
            'items' => $items,
            'total' => $this->repository->count($filters),
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->repository->find($id);

        if ($row === null) {
            return null;
        }

        return $this->detail($row, $this->inspector->inspect($id));
    }

    /**
     * @param array<string, mixed>|null $references
     * @return array<string, mixed>
     */
    private function listItem(array $row, ?array $references): array
    {
        $id = (int) $row['inventory_id'];
        $productId = (int) $row['product_id'];
        $storeId = (int) $row['minimarket_id'];
        $availability = $this->availability->evaluate(
            $this->availabilitySnapshot($row)
        );
        $referenceSummary = $references ?? [
            'inspection_status' => 'failed',
            'cart' => ['total' => 0],
            'reservations' => ['active' => 0, 'total' => 0],
            'order_items' => ['total' => 0],
        ];

        return [
            'id' => $id,
            'product' => [
                'id' => $productId,
                'exists' => $row['resolved_product_id'] !== null,
                'name' => $this->optionalText($row['product_name']),
                'sku' => $this->optionalText($row['product_sku']),
                'status' => $this->optionalText($row['product_status']),
            ],
            'store' => [
                'id' => $storeId,
                'exists' => $row['resolved_store_id'] !== null,
                'name' => $this->optionalText($row['store_name']),
                'location_label' => $this->locationLabel($row),
                'status' => $this->optionalText($row['store_status']),
                'lifecycle_state' => $this->storeLifecycleState($row),
            ],
            'price' => (string) $row['price'],
            'stock' => (int) $row['stock'],
            'status' => (string) $row['inventory_status'],
            'availability' => $availability,
            'references' => [
                'has_cart_items' =>
                    (int) $referenceSummary['cart']['total'] > 0,
                'has_active_reservations' =>
                    (int) $referenceSummary['reservations']['active'] > 0,
                'has_history' =>
                    (int) $referenceSummary['order_items']['total'] > 0
                    || (int) (
                        $referenceSummary['reservations']['released'] ?? 0
                    ) > 0
                    || (int) (
                        $referenceSummary['reservations']['expired'] ?? 0
                    ) > 0
                    || (int) (
                        $referenceSummary['reservations']['consumed'] ?? 0
                    ) > 0,
                'inspection_status' =>
                    (string) $referenceSummary['inspection_status'],
            ],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'version' => (string) $row['updated_at'],
            'actions' => $this->actions(
                (string) $row['inventory_status'],
                $row['resolved_product_id'] !== null,
                $row['resolved_store_id'] !== null
            ),
            'routes' => $this->routes(
                $id,
                $productId,
                $storeId,
                $row['resolved_product_id'] !== null,
                $row['resolved_store_id'] !== null
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(array $row, array $references): array
    {
        $id = (int) $row['inventory_id'];
        $productId = (int) $row['product_id'];
        $storeId = (int) $row['minimarket_id'];
        $productExists = $row['resolved_product_id'] !== null;
        $storeExists = $row['resolved_store_id'] !== null;
        $status = (string) $row['inventory_status'];
        $updatedAt = (string) $row['updated_at'];

        return [
            'identity' => [
                'id' => $id,
                'created_at' => (string) $row['created_at'],
                'updated_at' => $updatedAt,
            ],
            'offer' => [
                'product_id' => $productId,
                'minimarket_id' => $storeId,
                'price' => (string) $row['price'],
                'stock' => (int) $row['stock'],
                'status' => $status,
            ],
            'product' => [
                'exists' => $productExists,
                'id' => $productId,
                'name' => $this->optionalText($row['product_name']),
                'slug' => $this->optionalText($row['product_slug']),
                'sku' => $this->optionalText($row['product_sku']),
                'status' => $this->optionalText($row['product_status']),
                'image' => $this->image($row['product_image_id']),
            ],
            'store' => [
                'exists' => $storeExists,
                'id' => $storeId,
                'name' => $this->optionalText($row['store_name']),
                'status' => $this->optionalText($row['store_status']),
                'onboarding_status' => $this->optionalText(
                    $row['store_onboarding_status']
                ),
                'approved_at' => $this->optionalText(
                    $row['store_approved_at']
                ),
                'lifecycle_state' => $this->storeLifecycleState($row),
                'location' => [
                    'commune' => $this->optionalText($row['store_commune']),
                    'city' => $this->optionalText($row['store_city']),
                    'region' => $this->optionalText($row['store_region']),
                ],
            ],
            'availability' => $this->availability->evaluate(
                $this->availabilitySnapshot($row)
            ),
            'references' => $references,
            'lifecycle' => [
                'status' => $status,
                'allowed_actions' => match ($status) {
                    'active' => ['edit', 'deactivate'],
                    'inactive' => ['edit', 'activate'],
                    default => [],
                },
            ],
            'concurrency' => [
                'version' => $updatedAt,
                'mode' => 'last_write_wins',
                'last_observed_at' => current_time('mysql'),
            ],
            'actions' => $this->actions(
                $status,
                $productExists,
                $storeExists
            ),
            'routes' => [
                ...$this->routes(
                    $id,
                    $productId,
                    $storeId,
                    $productExists,
                    $storeExists
                ),
                'list' => $this->inventoryUrl(),
                'edit' => $this->inventoryUrl([
                    'action' => 'edit',
                    'inventory_id' => $id,
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function availabilitySnapshot(array $row): array
    {
        return [
            'inventory_exists' => true,
            'product_id' => $row['product_id'],
            'minimarket_id' => $row['minimarket_id'],
            'resolved_product_id' => $row['resolved_product_id'],
            'resolved_store_id' => $row['resolved_store_id'],
            'product_exists' => $row['resolved_product_id'] !== null,
            'store_exists' => $row['resolved_store_id'] !== null,
            'inventory_status' => $row['inventory_status'],
            'product_status' => $row['product_status'],
            'store_status' => $row['store_status'],
            'store_onboarding_status' => $row['store_onboarding_status'],
            'store_approved_at' => $row['store_approved_at'],
            'price' => $row['price'],
            'stock' => $row['stock'],
        ];
    }

    /** @return array<string, bool> */
    private function actions(
        string $status,
        bool $productExists,
        bool $storeExists
    ): array {
        $referencesResolve = $productExists && $storeExists;

        return [
            'view' => true,
            'edit' => $referencesResolve,
            'activate' => $referencesResolve && $status === 'inactive',
            'deactivate' => $referencesResolve && $status === 'active',
        ];
    }

    /** @return array<string, string|null> */
    private function routes(
        int $inventoryId,
        int $productId,
        int $storeId,
        bool $productExists,
        bool $storeExists
    ): array {
        return [
            'detail' => $this->inventoryUrl([
                'action' => 'view',
                'inventory_id' => $inventoryId,
            ]),
            'product' => $productExists && $productId > 0
                ? add_query_arg(
                    [
                        'page' => 'veciahorra-products',
                        'action' => 'view',
                        'product_id' => $productId,
                    ],
                    admin_url('admin.php')
                )
                : null,
            'store' => $storeExists && $storeId > 0
                ? add_query_arg(
                    [
                        'page' => 'veciahorra-stores',
                        'action' => 'view',
                        'id' => $storeId,
                    ],
                    admin_url('admin.php')
                )
                : null,
        ];
    }

    private function inventoryUrl(array $query = []): string
    {
        return (string) add_query_arg(
            ['page' => 'veciahorra-inventory', ...$query],
            admin_url('admin.php')
        );
    }

    private function locationLabel(array $row): ?string
    {
        $parts = array_values(array_unique(array_filter([
            $this->optionalText($row['store_commune']),
            $this->optionalText($row['store_city']),
            $this->optionalText($row['store_region']),
        ])));

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function storeLifecycleState(array $row): ?string
    {
        if ($row['resolved_store_id'] === null) {
            return null;
        }

        return $this->storeLifecycle->classify(
            (string) ($row['store_status'] ?? ''),
            (string) ($row['store_onboarding_status'] ?? ''),
            $row['store_approved_at'] ?? null
        );
    }

    /** @return array{id: int|null, url: string|null, status: string} */
    private function image(mixed $imageId): array
    {
        $id = is_numeric($imageId) ? (int) $imageId : 0;

        if ($id <= 0) {
            return ['id' => null, 'url' => null, 'status' => 'absent'];
        }

        $attachment = get_post($id);

        if (! $attachment instanceof WP_Post) {
            return [
                'id' => $id,
                'url' => null,
                'status' => 'missing_attachment',
            ];
        }

        if (
            $attachment->post_type !== 'attachment'
            || ! str_starts_with(
                (string) $attachment->post_mime_type,
                'image/'
            )
        ) {
            return ['id' => $id, 'url' => null, 'status' => 'unavailable'];
        }

        $url = wp_get_attachment_image_url($id, 'medium');

        return [
            'id' => $id,
            'url' => is_string($url) ? esc_url_raw($url) : null,
            'status' => is_string($url) ? 'valid' : 'unavailable',
        ];
    }

    private function optionalText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}
