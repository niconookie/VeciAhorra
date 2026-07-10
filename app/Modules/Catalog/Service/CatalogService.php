<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Catalog\Service;

use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\ProductCatalogs\Services\BrandService;
use VeciAhorra\Modules\ProductCatalogs\Services\CatalogService as ProductCatalogService;
use VeciAhorra\Modules\ProductCatalogs\Services\CategoryService;
use VeciAhorra\Modules\ProductCatalogs\Services\UnitService;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;

final class CatalogService
{
    private const READ_BATCH_SIZE = 200;

    /** @var array<string, array<int, array{id: int, name: string}>> */
    private array $catalogMaps = [];

    public function __construct(
        private ProductRepository $products,
        private InventoryRepository $inventory,
        private CategoryService $categories,
        private BrandService $brands,
        private UnitService $units
    ) {
    }

    /** @param array{category: ?int, brand: ?int, search: ?string, page: int, per_page: int, order_by: string} $filters */
    public function list(array $filters): array
    {
        $available = $this->availableInventory();
        $products = [];

        foreach ($this->productCandidates($filters['search']) as $product) {
            if (! $product instanceof Product || ! $this->isVisible($product, $available)) {
                continue;
            }

            if ($filters['category'] !== null && (int) $product->category_id !== $filters['category']) {
                continue;
            }

            if ($filters['brand'] !== null && (int) $product->brand_id !== $filters['brand']) {
                continue;
            }

            $products[] = $this->serialize($product, $available[(int) $product->id]);
        }

        $this->sort($products, $filters['order_by']);
        $products = array_map(static function (array $product): array {
            unset($product['_created_at']);

            return $product;
        }, $products);
        $total = count($products);
        $offset = ($filters['page'] - 1) * $filters['per_page'];

        return [
            'items' => array_values(array_slice($products, $offset, $filters['per_page'])),
            'meta' => [
                'page' => $filters['page'],
                'per_page' => $filters['per_page'],
                'total' => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $filters['per_page']),
            ],
        ];
    }

    public function find(int $id): array
    {
        if ($id <= 0) {
            throw new RecordNotFoundException('Catalog product not found.');
        }

        $product = $this->products->findById($id);
        $available = $this->availableInventory($id);

        if ($product === null || ! $this->isVisible($product, $available)) {
            throw new RecordNotFoundException('Catalog product not found.');
        }

        $result = $this->serialize($product, $available[$id]);
        unset($result['_created_at']);

        return $result;
    }

    /** @return array<int, array{min_price: string, minimarkets: array<int, true>}> */
    private function availableInventory(?int $productId = null): array
    {
        $available = [];
        $page = 1;

        do {
            $rows = $this->inventory->paginate([
                'status' => 'active',
                'product_id' => $productId,
                'page' => $page,
                'per_page' => self::READ_BATCH_SIZE,
            ]);

            foreach ($rows as $row) {
                $stock = (int) ($row['stock'] ?? 0);
                $rowProductId = (int) ($row['product_id'] ?? 0);
                $minimarketId = (int) ($row['minimarket_id'] ?? 0);
                $rawPrice = $row['price'] ?? null;

                if (
                    $stock <= 0
                    || $rowProductId <= 0
                    || $minimarketId <= 0
                    || ! is_numeric($rawPrice)
                    || ! is_finite((float) $rawPrice)
                    || (float) $rawPrice <= 0
                ) {
                    continue;
                }

                $price = number_format((float) $rawPrice, 2, '.', '');

                if (! isset($available[$rowProductId])) {
                    $available[$rowProductId] = [
                        'min_price' => $price,
                        'minimarkets' => [],
                    ];
                } elseif ((float) $price < (float) $available[$rowProductId]['min_price']) {
                    $available[$rowProductId]['min_price'] = $price;
                }

                $available[$rowProductId]['minimarkets'][$minimarketId] = true;
            }

            $page++;
        } while (count($rows) === self::READ_BATCH_SIZE);

        return $available;
    }

    /** @return iterable<Product> */
    private function productCandidates(?string $search): iterable
    {
        $page = 1;

        do {
            $batch = $this->products->paginate(
                $page,
                self::READ_BATCH_SIZE,
                $search,
                Product::STATUS_ACTIVE,
                'id',
                'DESC'
            );

            foreach ($batch as $product) {
                if ($product instanceof Product) {
                    yield $product;
                }
            }

            $page++;
        } while (count($batch) === self::READ_BATCH_SIZE);
    }

    /** @param array<int, array{min_price: string, minimarkets: array<int, true>}> $available */
    private function isVisible(Product $product, array $available): bool
    {
        return $product->status === Product::STATUS_ACTIVE
            && (int) $product->id > 0
            && isset($available[(int) $product->id]);
    }

    /** @param array{min_price: string, minimarkets: array<int, true>} $availability */
    private function serialize(Product $product, array $availability): array
    {
        $description = wp_strip_all_tags((string) ($product->description ?? ''));

        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'slug' => (string) $product->slug,
            'short_description' => wp_trim_words($description, 30, '…'),
            'image' => $this->image((int) ($product->image_id ?? 0)),
            'category' => $this->catalogItem($this->categories, (int) ($product->category_id ?? 0)),
            'brand' => $this->catalogItem($this->brands, (int) ($product->brand_id ?? 0)),
            'unit' => $this->catalogItem($this->units, (int) ($product->unit_id ?? 0)),
            'min_price' => $availability['min_price'],
            'available_minimarkets' => count($availability['minimarkets']),
            '_created_at' => (string) $product->created_at,
        ];
    }

    private function image(int $imageId): ?string
    {
        if ($imageId <= 0) {
            return null;
        }

        $url = wp_get_attachment_image_url($imageId, 'medium');

        return is_string($url) ? $url : null;
    }

    private function catalogItem(ProductCatalogService $service, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $key = $service::class;

        if (! isset($this->catalogMaps[$key])) {
            $this->catalogMaps[$key] = [];

            foreach ($service->all() as $item) {
                $this->catalogMaps[$key][(int) $item['id']] = $item;
            }
        }

        return $this->catalogMaps[$key][$id] ?? null;
    }

    /** @param list<array<string, mixed>> $products */
    private function sort(array &$products, string $orderBy): void
    {
        usort($products, static function (array $left, array $right) use ($orderBy): int {
            if ($orderBy === 'price') {
                $price = (float) $left['min_price'] <=> (float) $right['min_price'];

                return $price !== 0 ? $price : strcasecmp($left['name'], $right['name']);
            }

            if ($orderBy === 'newest') {
                $created = strcmp($right['_created_at'], $left['_created_at']);

                return $created !== 0 ? $created : $right['id'] <=> $left['id'];
            }

            return strcasecmp($left['name'], $right['name']);
        });
    }
}
