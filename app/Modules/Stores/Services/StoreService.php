<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Services;

use InvalidArgumentException;
use VeciAhorra\Core\CrudService;
use VeciAhorra\Database\Collection;
use VeciAhorra\Modules\Stores\Domain\StoreLifecycleContract;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

/**
 * Servicio de Minimarkets.
 */
final class StoreService extends CrudService
{
    private StoreLifecycleContract $lifecycle;

    public function bulkUpdateStatus(
        array $ids,
        string $status
    ): int {
        $allowedStatuses = $this->lifecycle->statuses();

        if (! in_array($status, $allowedStatuses, true)) {
            throw new InvalidArgumentException(
                'El estado del minimarket no es válido.'
            );
        }

        foreach ($ids as $id) {
            $store = $this->find((int) $id);
            if ($store === null) {
                continue;
            }

            $this->lifecycle->validate(
                $status,
                (string) $store->onboarding_status,
                $store->approved_at
            );
        }

        return $this->repository->bulkUpdateStatus(
            $ids,
            $status,
            current_time('mysql')
        );
    }

    public function __construct()
    {
        $this->repository = new StoreRepository();
        $this->lifecycle = new StoreLifecycleContract();
    }

    public function lifecycle(): StoreLifecycleContract
    {
        return $this->lifecycle;
    }

    public function create(array $data): int
    {
        $data['status'] = StoreLifecycleContract::STATUS_PENDING;
        $data['onboarding_status'] = StoreLifecycleContract::ONBOARDING_DRAFT;
        $data['approved_at'] = null;

        $this->lifecycle->validate(
            $data['status'],
            $data['onboarding_status'],
            $data['approved_at']
        );

        return parent::create($data);
    }

    public function update(int $id, array $data): void
    {
        unset($data['status'], $data['onboarding_status'], $data['approved_at']);

        parent::update($id, $data);
    }

    /**
     * Busca minimarkets.
     */
    public function search(
        ?string $term
    ): Collection {

        return $this->repository->search(
            $term
        );
    }

    /**
     * Obtiene una página de resultados.
     */
    public function paginate(
        int $page,
        int $perPage,
        ?string $term = null,
        ?string $status = null,
        string $orderBy = 'id',
        string $direction = 'DESC'
    ): Collection {

        return $this->repository->paginate(
            $page,
            $perPage,
            $term,
            $status,
            $orderBy,
            $direction
        );
    }

    /**
     * Cuenta los registros.
     */
    public function count(
        ?string $term = null,
        ?string $status = null
    ): int {

        return $this->repository->count(
            $term,
            $status
        );
    }
}
