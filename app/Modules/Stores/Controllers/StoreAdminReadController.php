<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Controllers;

use RuntimeException;
use Throwable;
use VeciAhorra\Modules\Stores\Services\StoreService;

/**
 * Caso HTTP neutral para la lectura administrativa paginada de Store.
 */
final class StoreAdminReadController
{
    private const ALLOWED_STATUSES = [
        'pending',
        'active',
        'inactive',
        'rejected',
    ];

    public function __construct(private StoreService $service)
    {
    }

    /** @param array<string, mixed> $query */
    public function index(array $query): array
    {
        try {
            $items = $this->service->paginate(
                $query['page'],
                $query['per_page'],
                $query['search'],
                $query['status'],
                $query['order_by'],
                $query['direction']
            );
            $total = $this->service->count(
                $query['search'],
                $query['status']
            );
            $page = (int) $query['page'];
            $perPage = (int) $query['per_page'];
            $totalPages = $total === 0
                ? 0
                : (int) ceil($total / $perPage);
            $data = [];

            foreach ($items as $store) {
                if (! is_object($store) || ! method_exists($store, 'toArray')) {
                    throw new RuntimeException(
                        'StoreService devolvio un registro no valido.'
                    );
                }

                $data[] = $this->serialize($store->toArray());
            }

            return [
                'success' => true,
                'data' => $data,
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages,
                    'has_next' => $page < $totalPages,
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'store_admin_unavailable',
                    'message' => 'No fue posible cargar los minimarkets.',
                ],
            ];
        }
    }

    /** @param array<string, mixed> $store */
    private function serialize(array $store): array
    {
        $id = $store['id'] ?? null;
        $name = $store['business_name'] ?? null;
        $status = $store['status'] ?? null;
        $onboarding = $store['onboarding_status'] ?? null;
        $approvedAt = $store['approved_at'] ?? null;

        if (
            ! is_numeric($id)
            || (int) $id <= 0
            || ! is_string($name)
            || trim($name) === ''
            || ! is_string($status)
            || ! in_array($status, self::ALLOWED_STATUSES, true)
            || ! is_string($onboarding)
            || trim($onboarding) === ''
            || ! ($approvedAt === null || is_string($approvedAt))
        ) {
            throw new RuntimeException(
                'StoreService devolvio datos no validos.'
            );
        }

        return [
            'id' => (int) $id,
            'name' => trim($name),
            'status' => $status,
            'onboarding_status' => trim($onboarding),
            'approved_at' => $approvedAt === null || trim($approvedAt) === ''
                ? null
                : trim($approvedAt),
            'location' => [
                'commune' => $this->optionalText($store['commune'] ?? null),
                'city' => $this->optionalText($store['city'] ?? null),
                'region' => $this->optionalText($store['region'] ?? null),
            ],
        ];
    }

    private function optionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException(
                'StoreService devolvio una ubicacion no valida.'
            );
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
