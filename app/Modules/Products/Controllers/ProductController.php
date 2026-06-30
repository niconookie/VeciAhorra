<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Controllers;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Products\Requests\ProductRequest;
use VeciAhorra\Modules\Products\Services\ProductService;

final class ProductController
{
    public function __construct(
        private ProductService $service
    ) {
    }

    public function index(
        int $page = 1,
        int $perPage = 20,
        ?string $term = null,
        ?string $status = null,
        string $orderBy = 'id',
        string $direction = 'DESC'
    ): array {
        return [
            'success' => false,
            'error' => [
                'code' => 'product_list_request_required',
                'message' => 'El listado de productos requiere ProductListRequest.',
            ],
        ];
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
