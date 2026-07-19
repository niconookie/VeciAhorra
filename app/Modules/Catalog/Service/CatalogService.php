<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Catalog\Service;

use VeciAhorra\Exceptions\CatalogUnavailableException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\ProductCatalogs\Services\BrandService;
use VeciAhorra\Modules\ProductCatalogs\Services\CatalogService as ProductCatalogService;
use VeciAhorra\Modules\ProductCatalogs\Services\CategoryService;
use VeciAhorra\Modules\ProductCatalogs\Services\UnitService;
use VeciAhorra\Modules\Products\Models\Product;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Stores\Models\Store;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;
use WP_Term;

final class CatalogService
{
    private const READ_BATCH_SIZE = 200;
    private const RELATED_LIMIT = 6;

    /** @var array<string, array<int, array{id: int, name: string}>> */
    private array $catalogMaps = [];

    public function __construct(
        private ProductRepository $products,
        private InventoryRepository $inventory,
        private CategoryService $categories,
        private BrandService $brands,
        private UnitService $units,
        private StoreRepository $stores
    ) {
    }

    /** @param array{category: ?int, brand: ?int, search: ?string, page: int, per_page: int, order_by: string} $filters */
    public function list(array $filters): array
    {
        $publicInventory = $this->publicInventory();
        $products = [];

        foreach ($this->productCandidates($filters['search']) as $product) {
            if (! $this->isVisible($product, $publicInventory['summaries'])) {
                continue;
            }

            if ($filters['category'] !== null && (int) $product->category_id !== $filters['category']) {
                continue;
            }

            if ($filters['brand'] !== null && (int) $product->brand_id !== $filters['brand']) {
                continue;
            }

            $products[] = $this->serializeSummary(
                $product,
                $publicInventory['summaries'][(int) $product->id]
            );
        }

        $this->sortSummaries($products, $filters['order_by']);
        $products = array_map([$this, 'withoutInternalFields'], $products);
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

        if ($product === null || $product->status !== Product::STATUS_ACTIVE) {
            throw new RecordNotFoundException('Catalog product not found.');
        }

        $publicInventory = $this->publicInventory([$id]);

        if (! $this->isVisible($product, $publicInventory['summaries'])) {
            throw new RecordNotFoundException('Catalog product not found.');
        }

        $offers = $publicInventory['offers'][$id];
        $summary = $publicInventory['summaries'][$id];
        $related = $this->relatedProducts($product);
        $result = $this->withoutInternalFields(
            $this->serializeSummary($product, $summary)
        );

        return array_merge($result, [
            'description' => wp_strip_all_tags((string) ($product->description ?? '')),
            'availability' => 'in_stock',
            'price' => [
                'min' => $summary['min_price'],
                'max' => $summary['max_price'],
                'offers' => count($offers),
            ],
            'offers' => $offers,
            'related_products' => $related,
            'meta' => [
                'related_products' => count($related),
            ],
        ]);
    }

    /** @return list<array{id: int, name: string, slug: string, products_count: int}> */
    public function categories(): array
    {
        $publicInventory = $this->publicInventory();
        $counts = [];

        foreach ($this->productCandidates(null) as $product) {
            if (! $this->isVisible($product, $publicInventory['summaries'])) {
                continue;
            }

            $categoryId = (int) ($product->category_id ?? 0);

            if ($categoryId > 0) {
                $counts[$categoryId] = ($counts[$categoryId] ?? 0) + 1;
            }
        }

        $categories = [];

        foreach ($counts as $categoryId => $productsCount) {
            $term = get_term($categoryId, 'product_cat');

            if (is_wp_error($term)) {
                throw new CatalogUnavailableException(
                    'Public catalog categories are temporarily unavailable.'
                );
            }

            if (! $term instanceof WP_Term || trim($term->name) === '' || trim($term->slug) === '') {
                continue;
            }

            $categories[] = [
                'id' => $categoryId,
                'name' => trim($term->name),
                'slug' => trim($term->slug),
                'products_count' => $productsCount,
            ];
        }

        usort($categories, static function (array $left, array $right): int {
            $name = strcasecmp($left['name'], $right['name']);

            return $name !== 0 ? $name : $left['id'] <=> $right['id'];
        });

        return $categories;
    }

    /**
     * @param list<int>|null $productIds
     * @return array{
     *   summaries: array<int, array{min_price: string, max_price: string, minimarkets: array<int, true>}>,
     *   offers: array<int, list<array{inventory_id: int, minimarket_id: int, minimarket: string, price: string, stock: int}>>
     * }
     */
    private function publicInventory(?array $productIds = null): array
    {
        $rows = $this->inventoryRows($productIds);
        $validRows = [];
        $storeIds = [];

        foreach ($rows as $row) {
            $stock = (int) ($row['stock'] ?? 0);
            $rawPrice = $row['price'] ?? null;
            $productId = (int) ($row['product_id'] ?? 0);
            $storeId = (int) ($row['minimarket_id'] ?? 0);
            $inventoryId = (int) ($row['id'] ?? 0);

            if (
                $inventoryId <= 0
                || $productId <= 0
                || $storeId <= 0
                || $stock <= 0
            ) {
                continue;
            }

            $price = $this->normalizePrice($rawPrice);

            if ($price === null) {
                continue;
            }

            $row['_public_price'] = $price;
            $validRows[] = $row;
            $storeIds[$storeId] = true;
        }

        $publicStores = $this->publicStores(array_keys($storeIds));
        $offers = [];

        foreach ($validRows as $row) {
            $storeId = (int) $row['minimarket_id'];

            if (! isset($publicStores[$storeId])) {
                continue;
            }

            $productId = (int) $row['product_id'];
            $offers[$productId][] = [
                'inventory_id' => (int) $row['id'],
                'minimarket_id' => $storeId,
                'minimarket' => $publicStores[$storeId],
                'price' => (string) $row['_public_price'],
                'stock' => (int) $row['stock'],
            ];
        }

        $summaries = [];

        foreach ($offers as $productId => &$productOffers) {
            usort($productOffers, static function (array $left, array $right): int {
                $price = (float) $left['price'] <=> (float) $right['price'];

                if ($price !== 0) {
                    return $price;
                }

                $stock = $right['stock'] <=> $left['stock'];

                return $stock !== 0
                    ? $stock
                    : $left['inventory_id'] <=> $right['inventory_id'];
            });
            $prices = array_column($productOffers, 'price');
            $minimarkets = [];

            foreach ($productOffers as $offer) {
                $minimarkets[$offer['minimarket_id']] = true;
            }

            $summaries[$productId] = [
                'min_price' => (string) reset($prices),
                'max_price' => (string) end($prices),
                'minimarkets' => $minimarkets,
            ];
        }
        unset($productOffers);

        return ['summaries' => $summaries, 'offers' => $offers];
    }

    private function normalizePrice(mixed $price): ?string
    {
        if (
            ! is_numeric($price)
            || ! is_finite((float) $price)
            || (float) $price <= 0
        ) {
            return null;
        }

        return number_format((float) $price, 2, '.', '');
    }

    /** @param list<int>|null $productIds @return list<array<string, mixed>> */
    private function inventoryRows(?array $productIds): array
    {
        if ($productIds !== null) {
            $rows = [];

            foreach (array_chunk(array_values(array_unique($productIds)), self::READ_BATCH_SIZE) as $ids) {
                array_push($rows, ...$this->inventory->findActiveByProductIds($ids));
            }

            return $rows;
        }

        $rows = [];
        $page = 1;

        do {
            $batch = $this->inventory->paginate([
                'status' => 'active',
                'page' => $page,
                'per_page' => self::READ_BATCH_SIZE,
            ]);
            array_push($rows, ...$batch);
            $page++;
        } while (count($batch) === self::READ_BATCH_SIZE);

        return $rows;
    }

    /** @param list<int> $ids @return array<int, string> */
    private function publicStores(array $ids): array
    {
        $stores = [];

        foreach (array_chunk($ids, self::READ_BATCH_SIZE) as $batch) {
            foreach ($this->stores->findActiveByIds($batch) as $store) {
                if ($store instanceof Store && (int) $store->id > 0) {
                    $stores[(int) $store->id] = (string) $store->business_name;
                }
            }
        }

        return $stores;
    }

    /** @return list<array<string, mixed>> */
    private function relatedProducts(Product $current): array
    {
        $categoryId = (int) ($current->category_id ?? 0);

        if ($categoryId <= 0) {
            return [];
        }

        $candidates = [];

        foreach ($this->productCandidates(null) as $product) {
            if ((int) $product->id !== (int) $current->id && (int) $product->category_id === $categoryId) {
                $candidates[] = $product;
            }
        }

        usort($candidates, static function (Product $left, Product $right): int {
            $name = strcasecmp((string) $left->name, (string) $right->name);

            return $name !== 0 ? $name : (int) $left->id <=> (int) $right->id;
        });
        $ids = array_map(static fn (Product $product): int => (int) $product->id, $candidates);
        $publicInventory = $this->publicInventory($ids);
        $related = [];

        foreach ($candidates as $product) {
            if (! $this->isVisible($product, $publicInventory['summaries'])) {
                continue;
            }

            $related[] = $this->withoutInternalFields($this->serializeSummary(
                $product,
                $publicInventory['summaries'][(int) $product->id]
            ));

            if (count($related) === self::RELATED_LIMIT) {
                break;
            }
        }

        return $related;
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

    /** @param array<int, array{min_price: string, max_price: string, minimarkets: array<int, true>}> $summaries */
    private function isVisible(Product $product, array $summaries): bool
    {
        return $product->status === Product::STATUS_ACTIVE
            && (int) $product->id > 0
            && isset($summaries[(int) $product->id]);
    }

    /** @param array{min_price: string, max_price: string, minimarkets: array<int, true>} $summary */
    private function serializeSummary(Product $product, array $summary): array
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
            'min_price' => $summary['min_price'],
            'available_minimarkets' => count($summary['minimarkets']),
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
    private function sortSummaries(array &$products, string $orderBy): void
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

    /** @param array<string, mixed> $product */
    private function withoutInternalFields(array $product): array
    {
        unset($product['_created_at']);

        return $product;
    }
}
