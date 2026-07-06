<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Controllers;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Inventory\Services\InventoryService;

/**
 * Adaptador de aplicacion del modulo Inventory.
 */
final class InventoryController
{
    public function __construct(
        private InventoryService $service
    ) {
    }

    public function index(array $query): array
    {
        try {
            $items = $this->service->paginate($query);
            $total = $this->service->count($query);
            $page = (int) ($query['page'] ?? 1);
            $perPage = (int) ($query['per_page'] ?? 20);

            return [
                'success' => true,
                'data' => $items,
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $total === 0
                        ? 0
                        : (int) ceil($total / $perPage),
                ],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function show(int $id): array
    {
        try {
            $inventory = $this->service->find($id);

            if ($inventory === null) {
                throw new RecordNotFoundException(
                    'El inventario solicitado no existe.'
                );
            }

            return [
                'success' => true,
                'data' => $inventory,
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function create(array $payload): array
    {
        try {
            $id = $this->service->create($payload);

            return [
                'success' => true,
                'data' => ['id' => $id],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function update(int $id, array $payload): array
    {
        try {
            $updated = $this->service->update($id, $payload);

            return [
                'success' => true,
                'data' => [
                    'id' => $id,
                    'updated' => $updated,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function delete(int $id): array
    {
        try {
            $deleted = $this->service->delete($id);

            return [
                'success' => true,
                'data' => [
                    'id' => $id,
                    'deleted' => $deleted,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function updatePrice(int $id, array $payload): array
    {
        try {
            $updated = $this->service->updatePrice(
                $id,
                $payload['price']
            );

            return [
                'success' => true,
                'data' => [
                    'id' => $id,
                    'price' => $payload['price'],
                    'updated' => $updated,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function updateStock(int $id, array $payload): array
    {
        try {
            $updated = $this->service->updateStock(
                $id,
                $payload['stock']
            );

            return [
                'success' => true,
                'data' => [
                    'id' => $id,
                    'stock' => $payload['stock'],
                    'updated' => $updated,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function changeStatus(int $id, array $payload): array
    {
        try {
            $updated = $this->service->changeStatus(
                $id,
                $payload['status']
            );

            return [
                'success' => true,
                'data' => [
                    'id' => $id,
                    'status' => $payload['status'],
                    'updated' => $updated,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    private function translateException(Throwable $exception): array
    {
        if ($exception instanceof RecordNotFoundException) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'inventory_not_found',
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

        if (
            $exception instanceof PersistenceException
            || $exception->getPrevious() instanceof PersistenceException
        ) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'persistence_error',
                    'message' => 'No fue posible completar la operacion.',
                ],
            ];
        }

        return [
            'success' => false,
            'error' => [
                'code' => 'internal_error',
                'message' => 'Ocurrio un error interno.',
            ],
        ];
    }
}
