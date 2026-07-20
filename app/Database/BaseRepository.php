<?php

declare(strict_types=1);

namespace VeciAhorra\Database;
use VeciAhorra\Exceptions\PersistenceException;
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
    $database = $this->db();
    $row = $database->get_row(
        $database->prepare(
            sprintf(
                'SELECT * FROM %s WHERE id = %%d',
                $this->table($this->table)
            ),
            $id
        ),
        ARRAY_A
    );

    if ($database->last_error !== '') {
        throw new PersistenceException(
            'No fue posible consultar el registro.'
        );
    }

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
        $database = $this->db();

        $result = $database->insert(
            $this->table($this->table),
            $data
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible crear el registro.'
            );
        }

        $id = (int) $database->insert_id;

        if ($id <= 0) {
            throw new PersistenceException(
                'No fue posible obtener el ID del registro creado.'
            );
        }

        return $id;
    }

    /**
     * Actualiza un registro.
     */
    public function update(
        int $id,
        array $data
    ): int {
        $result = $this->db()->update(
            $this->table($this->table),
            $data,
            ['id' => $id]
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible actualizar el registro.'
            );
        }

        return $result;
    }

    /**
     * Elimina un registro.
     */
    public function delete(int $id): int
    {
        $result = $this->db()->delete(
            $this->table($this->table),
            ['id' => $id]
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible eliminar el registro.'
            );
        }

        return $result;
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
