<?php

declare(strict_types=1);

namespace VeciAhorra\Core;

use VeciAhorra\Database\BaseRepository;
use VeciAhorra\Database\Collection;
use VeciAhorra\Database\Model;

/**
 * Servicio CRUD base.
 */
abstract class CrudService
{
    /**
     * Repositorio asociado.
     */
    protected BaseRepository $repository;

    /**
     * Obtiene todos los registros.
     */
    public function all(): Collection
    {
        return $this->repository->all();
    }

    /**
     * Obtiene un registro.
     */
    public function find(int $id): ?Model
    {
        return $this->repository->find($id);
    }

    /**
     * Crea un registro.
     */
    public function create(array $data): int
    {
        return $this->repository->create($data);
    }

    /**
     * Actualiza un registro.
     */
    public function update(
        int $id,
        array $data
    ): bool {

        return $this->repository->update(
            $id,
            $data
        );
    }

    /**
     * Elimina un registro.
     */
    public function delete(
        int $id
    ): bool {

        return $this->repository->delete($id);
    }
}