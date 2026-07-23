<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Controllers;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Exceptions\CatalogUnavailableException;
use VeciAhorra\Exceptions\CatalogValidationException;
use VeciAhorra\Modules\Products\Exceptions\ProductConcurrencyException;
use VeciAhorra\Modules\Products\Exceptions\ProductDeletionException;
use VeciAhorra\Modules\Products\Exceptions\ProductLifecycleException;
use VeciAhorra\Modules\Products\Requests\ProductBulkRequest;
use VeciAhorra\Modules\Products\Requests\ProductListRequest;
use VeciAhorra\Modules\Products\Requests\ProductRequest;
use VeciAhorra\Modules\Products\Services\ProductService;

final class ProductController
{
    public function __construct(
        private ProductService $service
    ) {
    }

    public function index(array $input): array
    {
        try {
            $request = new ProductListRequest($input);
            $query = $request->validated();

            $products = $this->service->paginate(
                $query['page'],
                $query['per_page'],
                $query['term'],
                $query['status'],
                $query['order_by'],
                $query['direction'],
                $query['category_id'],
                $query['brand_id']
            );
            $total = $this->service->count(
                $query['term'],
                $query['status'],
                $query['category_id'],
                $query['brand_id']
            );
            $rows = $products->toArray();
            $this->primeImages($rows);

            return [
                'success' => true,
                'data' => array_map(
                    [$this, 'serializeAdminListProduct'],
                    $rows
                ),
                'meta' => [
                    'page' => $query['page'],
                    'per_page' => $query['per_page'],
                    'total' => $total,
                    'total_pages' => $total === 0
                        ? 0
                        : (int) ceil(
                            $total / $query['per_page']
                        ),
                ],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    private function primeImages(array $products): void
    {
        $ids = [];
        foreach ($products as $product) {
            $id = isset($product['image_id']) ? (int) $product['image_id'] : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        if ($ids !== [] && function_exists('_prime_post_caches')) {
            _prime_post_caches(array_values(array_unique($ids)), false, true);
        }
    }

    private function serializeAdminListProduct(array $product): array
    {
        $status = (string) ($product['status'] ?? '');
        $targets = (new \VeciAhorra\Modules\Products\Domain\ProductLifecycleContract())
            ->targets($status);

        return [
            'id' => (int) ($product['id'] ?? 0),
            'name' => (string) ($product['name'] ?? ''),
            'slug' => (string) ($product['slug'] ?? ''),
            'sku' => $product['sku'] === null ? null : (string) $product['sku'],
            'status' => $status,
            'created_at' => (string) ($product['created_at'] ?? ''),
            'updated_at' => (string) ($product['updated_at'] ?? ''),
            'image_id' => isset($product['image_id']) ? (int) $product['image_id'] : null,
            'image_url' => $this->adminImageUrl($product['image_id'] ?? null),
            'category' => $this->taxonomyValue($product, 'category'),
            'brand' => $this->taxonomyValue($product, 'brand'),
            'unit' => $this->taxonomyValue($product, 'unit'),
            'inventory' => [
                'total' => (int) ($product['inventory_total'] ?? 0),
                'active' => (int) ($product['inventory_active'] ?? 0),
                'inactive' => (int) ($product['inventory_inactive'] ?? 0),
            ],
            'publicly_available' => (int) ($product['publicly_available'] ?? 0) > 0,
            'allowed_statuses' => $targets,
        ];
    }

    private function taxonomyValue(array $product, string $key): array
    {
        $id = isset($product[$key . '_id']) ? (int) $product[$key . '_id'] : 0;
        $name = trim((string) ($product[$key . '_name'] ?? ''));
        $taxonomy = match ($key) {
            'category' => 'product_cat',
            'brand' => 'product_brand',
            'unit' => 'pa_unidad',
            default => '',
        };
        $registered = $taxonomy !== '' && taxonomy_exists($taxonomy);

        return [
            'id' => $id > 0 ? $id : null,
            'name' => $registered && $name !== '' ? $name : null,
            'available' => $registered && ($id <= 0 || $name !== ''),
        ];
    }

    private function adminImageUrl(mixed $imageId): ?string
    {
        $id = is_numeric($imageId) ? (int) $imageId : 0;
        if ($id <= 0) {
            return null;
        }

        $url = wp_get_attachment_image_url($id, 'thumbnail');

        return is_string($url) && $url !== '' ? esc_url_raw($url) : null;
    }

    public function show(int $id): array
    {
        try {
            $product = $this->service->find($id);

            if ($product === null) {
                throw new RecordNotFoundException(
                    'El producto solicitado no existe.'
                );
            }

            return [
                'success' => true,
                'data' => $product->toArray(),
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function store(array $input): array
    {
        try {
            $request = new ProductRequest($input);
            $data = $request->validateForCreate();
            $id = $this->service->create($data);

            return [
                'success' => true,
                'data' => [
                    'id' => $id,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function update(int $id, array $input): array
    {
        try {
            $request = new ProductRequest($input);
            $data = $request->validateForUpdate();
            $expectedUpdatedAt =
                $request->validateExpectedUpdatedAt();

            $updatedAt = $this->service->update(
                $id,
                $data,
                $expectedUpdatedAt
            );

            return [
                'success' => true,
                'data' => [
                    'id' => $id,
                    'updated' => true,
                    'updated_at' => $updatedAt,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function updateStatus(
        int $id,
        array $input
    ): array {
        try {
            $request = new ProductRequest($input);
            $data = $request->validateForStatusChange();
            $expectedUpdatedAt =
                $request->validateExpectedUpdatedAt();

            $updatedAt = $this->service->updateStatus(
                $id,
                $data['status'],
                $expectedUpdatedAt
            );

            return [
                'success' => true,
                'data' => [
                    'id' => $id,
                    'status' => $data['status'],
                    'updated_at' => $updatedAt,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function destroy(int $id, array $input): array
    {
        try {
            $request = new ProductRequest($input);
            $expectedUpdatedAt = $request->validateForDelete();
            $inspection = $this->service->delete(
                $id,
                $expectedUpdatedAt
            );

            return [
                'success' => true,
                'data' => [
                    'id' => $id,
                    'deleted' => true,
                    'classification' => $inspection->classification(),
                ],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function bulkUpdateStatus(array $input): array
    {
        try {
            $request = new ProductBulkRequest($input);
            $data = $request->validateForStatus();
            $affected = $this->service->bulkUpdateStatus(
                $data['ids'],
                $data['status']
            );

            return [
                'success' => true,
                'data' => [
                    'requested' => count($data['ids']),
                    'affected' => $affected,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function bulkUpdateCategory(array $input): array
    {
        try {
            $request = new ProductBulkRequest($input);
            $data = $request->validateForCategory();
            $affected = $this->service->bulkUpdateCategory(
                $data['ids'],
                $data['category_id']
            );

            return [
                'success' => true,
                'data' => [
                    'requested' => count($data['ids']),
                    'affected' => $affected,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function bulkUpdateBrand(array $input): array
    {
        try {
            $request = new ProductBulkRequest($input);
            $data = $request->validateForBrand();
            $affected = $this->service->bulkUpdateBrand(
                $data['ids'],
                $data['brand_id']
            );

            return [
                'success' => true,
                'data' => [
                    'requested' => count($data['ids']),
                    'affected' => $affected,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function bulkUpdateUnit(array $input): array
    {
        try {
            $request = new ProductBulkRequest($input);
            $data = $request->validateForUnit();
            $affected = $this->service->bulkUpdateUnit(
                $data['ids'],
                $data['unit_id']
            );

            return [
                'success' => true,
                'data' => [
                    'requested' => count($data['ids']),
                    'affected' => $affected,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function deactivate(int $id): array
    {
        try {
            $this->service->deactivate($id);

            return [
                'success' => true,
                'data' => [
                    'id' => $id,
                    'deactivated' => true,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    private function translateException(
        Throwable $exception
    ): array {
        if ($exception instanceof ProductConcurrencyException) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'product_concurrency_conflict',
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        if ($exception instanceof ProductDeletionException) {
            return [
                'success' => false,
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                    'references' => $exception->inspection()->toArray(),
                ],
            ];
        }

        if ($exception instanceof ProductLifecycleException) {
            return [
                'success' => false,
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        if ($exception instanceof CatalogValidationException) {
            return [
                'success' => false,
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        if ($exception instanceof CatalogUnavailableException) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'catalog_unavailable',
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        if ($exception instanceof RecordNotFoundException) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'product_not_found',
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        if ($exception instanceof InvalidArgumentException) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'validation_error',
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        if ($exception instanceof PersistenceException) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'persistence_error',
                    'message' => 'No fue posible completar la operación.',
                ],
            ];
        }

        return [
            'success' => false,
            'error' => [
                'code' => 'internal_error',
                'message' => 'Ocurrió un error interno.',
            ],
        ];
    }
}
