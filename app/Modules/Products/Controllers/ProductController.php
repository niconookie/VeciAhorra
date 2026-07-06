<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Controllers;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Exceptions\CatalogUnavailableException;
use VeciAhorra\Exceptions\CatalogValidationException;
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
                $query['direction']
            );
            $total = $this->service->count(
                $query['term'],
                $query['status']
            );

            return [
                'success' => true,
                'data' => $products->toArray(),
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

            $this->service->update($id, $data);

            return [
                'success' => true,
                'data' => [
                    'id' => $id,
                    'updated' => true,
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

            $this->service->updateStatus(
                $id,
                $data['status']
            );

            return [
                'success' => true,
                'data' => [
                    'id' => $id,
                    'status' => $data['status'],
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
