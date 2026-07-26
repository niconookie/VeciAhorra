<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Controllers;

use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Inventory\Services\InventoryAdminReadService;

/**
 * Adaptador neutral para lecturas operacionales de Inventory Admin.
 */
final class InventoryAdminReadController
{
    public function __construct(
        private InventoryAdminReadService $service
    ) {
    }

    public function index(array $query): array
    {
        try {
            $result = $this->service->paginate($query);
            $page = (int) $query['page'];
            $perPage = (int) $query['per_page'];
            $total = $result['total'];

            return [
                'success' => true,
                'data' => $result['items'],
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $total === 0
                        ? 0
                        : (int) ceil($total / $perPage),
                    'order_by' => $query['order_by'],
                    'direction' => $query['direction'],
                    'snapshot_consistent' => false,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    public function show(int $id): array
    {
        try {
            $item = $this->service->find($id);

            if ($item === null) {
                return [
                    'success' => false,
                    'error' => [
                        'code' => 'inventory_not_found',
                        'message' => 'El inventario no existe o no es legible.',
                    ],
                ];
            }

            return ['success' => true, 'data' => $item];
        } catch (Throwable $exception) {
            return $this->error($exception);
        }
    }

    private function error(Throwable $exception): array
    {
        error_log(sprintf(
            '[VeciAhorra] Inventory Admin read failed: %s',
            $exception::class
        ));

        return [
            'success' => false,
            'error' => [
                'code' => $exception instanceof PersistenceException
                    || $exception->getPrevious() instanceof PersistenceException
                        ? 'inventory_read_failed'
                        : 'inventory_inconsistent_state',
                'message' => 'No fue posible leer Inventory Admin.',
            ],
        ];
    }
}
