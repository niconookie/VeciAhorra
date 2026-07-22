<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Controllers;

use RuntimeException;
use Throwable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract;
use VeciAhorra\Modules\Stores\Exceptions\StoreLifecycleException;
use VeciAhorra\Modules\Stores\Models\Store;
use VeciAhorra\Modules\Stores\Services\StoreService;
use VeciAhorra\Modules\Stores\Services\StoreTransitionService;

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

    public function __construct(
        private StoreService $service,
        private StoreTransitionService $transitions,
        private StoreLifecycleContract $lifecycle
    ) {
    }

    public function show(int $id): array
    {
        try {
            $store = $this->service->find($id);
            if (! $store instanceof Store) {
                throw new StoreLifecycleException('store_not_found', 'El minimarket no existe.', 'id');
            }

            return ['success' => true, 'data' => $this->serializeDetail($store->toArray())];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function transition(int $id, string $action): array
    {
        try {
            $store = match ($action) {
                StoreLifecycleContract::ACTION_SUBMIT_FOR_REVIEW => $this->transitions->submitForReview($id),
                StoreLifecycleContract::ACTION_RETURN_TO_DRAFT => $this->transitions->returnToDraft($id),
                StoreLifecycleContract::ACTION_APPROVE => $this->transitions->approve($id),
                StoreLifecycleContract::ACTION_REJECT => $this->transitions->reject($id),
                StoreLifecycleContract::ACTION_ACTIVATE => $this->transitions->activate($id),
                StoreLifecycleContract::ACTION_DEACTIVATE => $this->transitions->deactivate($id),
                default => throw new RuntimeException('Accion no despachada.'),
            };

            return ['success' => true, 'data' => $this->serializeDetail($store->toArray())];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    public function delete(int $id): array
    {
        try {
            $this->service->delete($id);
            return ['success' => true, 'data' => null];
        } catch (Throwable $exception) {
            return $this->translateException($exception);
        }
    }

    /** @param array<string, mixed> $query */
    public function index(array $query): array
    {
        try {
            $admin = ($query['context'] ?? null) === 'admin_list';
            $items = $admin ? $this->service->paginateAdmin(
                $query['page'],
                $query['per_page'],
                $query['search'],
                $query['status'],
                $query['lifecycle_state'],
                $query['order_by'],
                $query['direction']
            ) : $this->service->paginate(
                $query['page'],
                $query['per_page'],
                $query['search'],
                $query['status'],
                $query['order_by'],
                $query['direction']
            );
            $total = $admin ? $this->service->countAdmin(
                $query['search'],
                $query['status'],
                $query['lifecycle_state']
            ) : $this->service->count(
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

                $data[] = $admin
                    ? $this->serializeAdminList($store->toArray())
                    : $this->serialize($store->toArray());
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

    private function serializeDetail(array $store): array
    {
        $status = (string) ($store['status'] ?? '');
        $onboarding = (string) ($store['onboarding_status'] ?? '');
        $approvedAt = $store['approved_at'] ?? null;
        $state = $this->lifecycle->classify($status, $onboarding, $approvedAt);

        return [
            'id' => (int) ($store['id'] ?? 0),
            'business_name' => (string) ($store['business_name'] ?? ''),
            'legal_name' => (string) ($store['legal_name'] ?? ''),
            'owner_name' => (string) ($store['owner_name'] ?? ''),
            'rut' => (string) ($store['rut'] ?? ''),
            'email' => (string) ($store['email'] ?? ''),
            'phone' => (string) ($store['phone'] ?? ''),
            'mobile' => $this->optionalText($store['mobile'] ?? null),
            'address' => $this->optionalText($store['address'] ?? null),
            'commune' => $this->optionalText($store['commune'] ?? null),
            'city' => $this->optionalText($store['city'] ?? null),
            'region' => $this->optionalText($store['region'] ?? null),
            'status' => $status,
            'onboarding_status' => $onboarding,
            'approved_at' => $approvedAt,
            'lifecycle_state' => $state,
            'allowed_actions' => $state === StoreLifecycleContract::STATE_INVALID
                ? []
                : $this->lifecycle->allowedActions($status, $onboarding, $approvedAt),
            'created_at' => (string) ($store['created_at'] ?? ''),
            'updated_at' => (string) ($store['updated_at'] ?? ''),
        ];
    }

    private function serializeAdminList(array $store): array
    {
        $status = (string) ($store['status'] ?? '');
        $onboarding = (string) ($store['onboarding_status'] ?? '');
        $approvedAt = $store['approved_at'] ?? null;
        $state = $this->lifecycle->classify($status, $onboarding, $approvedAt);

        return [
            'id' => (int) ($store['id'] ?? 0),
            'business_name' => (string) ($store['business_name'] ?? ''),
            'legal_name' => $this->optionalText($store['legal_name'] ?? null),
            'rut' => $this->optionalText($store['rut'] ?? null),
            'email' => (string) ($store['email'] ?? ''),
            'phone' => $this->optionalText($store['phone'] ?? null),
            'commune' => $this->optionalText($store['commune'] ?? null),
            'city' => $this->optionalText($store['city'] ?? null),
            'status' => $status,
            'onboarding_status' => $onboarding,
            'approved_at' => $approvedAt,
            'lifecycle_state' => $state,
            'allowed_actions' => $state === StoreLifecycleContract::STATE_INVALID
                ? []
                : $this->lifecycle->allowedActions($status, $onboarding, $approvedAt),
            'created_at' => (string) ($store['created_at'] ?? ''),
            'updated_at' => (string) ($store['updated_at'] ?? ''),
        ];
    }

    private function translateException(Throwable $exception): array
    {
        if ($exception instanceof StoreLifecycleException) {
            $data = [
                'reason' => $exception->reason(),
                'state' => $exception->state(),
                'action' => $exception->action(),
            ];
            if ($exception->field() !== null) {
                $data['field'] = $exception->field();
            }
            if ($exception->reason() === 'store_referenced') {
                $data['domains'] = $exception->domains();
                $data['counts'] = $exception->counts();
            }

            return [
                'success' => false,
                'error' => [
                    'code' => $exception->reason(),
                    'message' => $exception->getMessage(),
                    'data' => $data,
                ],
            ];
        }

        if ($exception instanceof PersistenceException || $exception->getPrevious() instanceof PersistenceException) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'persistence_failure',
                    'message' => 'No fue posible completar la operacion Store.',
                    'data' => ['reason' => 'persistence_failure'],
                ],
            ];
        }

        return [
            'success' => false,
            'error' => [
                'code' => 'internal_error',
                'message' => 'Ocurrio un error interno.',
                'data' => ['reason' => 'internal_error'],
            ],
        ];
    }
}
