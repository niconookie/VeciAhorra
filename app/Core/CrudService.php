<?php

declare(strict_types=1);

namespace VeciAhorra\Core;

use VeciAhorra\Database\BaseRepository;
use VeciAhorra\Database\Collection;
use VeciAhorra\Database\Model;
use VeciAhorra\Exceptions\RecordNotFoundException;

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
    ): void {
        $affectedRows = $this->repository->update(
            $id,
            $data
        );

        if (
            $affectedRows === 0
            && $this->repository->find($id) === null
        ) {
            throw new RecordNotFoundException(
                'El registro que intentas actualizar no existe.'
            );
        }
    }

    /**
     * Elimina un registro.
     */
    public function delete(
        int $id
    ): void {
        $affectedRows = $this->repository->delete($id);

        if ($affectedRows === 0) {
            throw new RecordNotFoundException(
                'El registro que intentas eliminar no existe.'
            );
        }
    }
}
