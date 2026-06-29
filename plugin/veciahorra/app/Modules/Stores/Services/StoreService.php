<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Services;

use VeciAhorra\Core\CrudService;
use VeciAhorra\Database\Collection;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;

/**
 * Servicio de Minimarkets.
 */
final class StoreService extends CrudService
{
    public function __construct()
    {
        $this->repository = new StoreRepository();
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