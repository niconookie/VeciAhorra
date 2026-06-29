<?php

declare(strict_types=1);

namespace VeciAhorra\Database;
use VeciAhorra\Database\Collection;
use VeciAhorra\Database\Model;

/**
 * Repositorio base del Framework.
 *
 * Implementa las operaciones CRUD comunes.
 */
abstract class BaseRepository extends Repository
{
    /**
     * Nombre lógico de la tabla.
     */
    protected string $table;

    /**
 * Modelo asociado al repositorio.
 */
abstract protected function model(): string;

    /**
     * Obtiene todos los registros.
     */
    public function all(): Collection
{
    $rows = $this->db()->get_results(
        sprintf(
            'SELECT * FROM %s ORDER BY id DESC',
            $this->table($this->table)
        ),
        ARRAY_A
    );

    $collection = new Collection();

    foreach ($rows as $row) {
        $collection->add(
            $this->hydrate($row)
        );
    }

    return $collection;
}

    /**
     * Busca un registro por ID.
     */
    public function find(int $id): ?Model
{
    $row = $this->db()->get_row(
        $this->db()->prepare(
            sprintf(
                'SELECT * FROM %s WHERE id = %%d',
                $this->table($this->table)
            ),
            $id
        ),
        ARRAY_A
    );

    if ($row === null) {
        return null;
    }

    return $this->hydrate($row);
}

    /**
     * Inserta un registro.
     */
    public function create(array $data): int
    {
        $this->db()->insert(
            $this->table($this->table),
            $data
        );

        return (int) $this->db()->insert_id;
    }

    /**
     * Actualiza un registro.
     */
    public function update(
        int $id,
        array $data
    ): bool {

        return false !== $this->db()->update(
            $this->table($this->table),
            $data,
            ['id' => $id]
        );
    }

    /**
     * Elimina un registro.
     */
    public function delete(int $id): bool
    {
        return false !== $this->db()->delete(
            $this->table($this->table),
            ['id' => $id]
        );
    }

 /**
 * Convierte un registro en un modelo.
 */
protected function hydrate(
    array $attributes
): Model {

    $model = $this->model();

    return new $model($attributes);
}
}