<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Repositories;

use VeciAhorra\Database\BaseRepository;
use VeciAhorra\Database\Collection;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Stores\Models\Store;

/**
 * Repositorio de Minimarkets.
 */
final class StoreRepository extends BaseRepository
{
    /**
     * Returns publicly active minimarkets for a bounded ID set.
     *
     * @param list<int> $ids
     */
    public function findActiveByIds(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
        $collection = new Collection();

        if ($ids === []) {
            return $collection;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $sql = sprintf(
            'SELECT *
             FROM %s
             WHERE status = %%s
               AND id IN (%s)
             ORDER BY id ASC',
            $this->table($this->table),
            $placeholders
        );
        $rows = $this->db()->get_results(
            $this->db()->prepare($sql, 'active', ...$ids),
            ARRAY_A
        );

        foreach ($rows as $row) {
            $collection->add($this->hydrate($row));
        }

        return $collection;
    }

    public function bulkUpdateStatus(
        array $ids,
        string $status,
        string $updatedAt
    ): int {
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(
            ', ',
            array_fill(0, count($ids), '%d')
        );

        $sql = sprintf(
            'UPDATE %s
             SET status = %%s, updated_at = %%s
             WHERE id IN (%s)',
            $this->table($this->table),
            $placeholders
        );

        $result = $this->db()->query(
            $this->db()->prepare(
                $sql,
                $status,
                $updatedAt,
                ...$ids
            )
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible actualizar el estado de los minimarkets.'
            );
        }

        return $result;
    }

    /**
     * Nombre lógico de la tabla.
     */
    protected string $table = 'stores';

    /**
     * Modelo asociado.
     */
    protected function model(): string
    {
        return Store::class;
    }

    /**
     * Busca minimarkets.
     */
    public function search(?string $term): Collection
    {
        if (empty($term)) {
            return $this->all();
        }

        $term = '%' . $this->db()->esc_like($term) . '%';

        $rows = $this->db()->get_results(
            $this->db()->prepare(
                sprintf(
                    'SELECT *
                     FROM %s
                     WHERE business_name LIKE %%s
                        OR owner_name LIKE %%s
                        OR email LIKE %%s
                        OR phone LIKE %%s
                     ORDER BY id DESC',
                    $this->table($this->table)
                ),
                $term,
                $term,
                $term,
                $term
            ),
            ARRAY_A
        );

        $collection = new Collection();

        foreach ($rows as $row) {
            $collection->add($this->hydrate($row));
        }

        return $collection;
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

        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $params = [];

        if (!empty($term)) {

            $conditions[] = '(
                business_name LIKE %s
                OR owner_name LIKE %s
                OR email LIKE %s
                OR phone LIKE %s
            )';

            $term = '%' . $this->db()->esc_like($term) . '%';

            $params = [
                $term,
                $term,
                $term,
                $term,
            ];
        }

        if (!empty($status)) {
            $conditions[] = 'status = %s';
            $params[] = $status;
        }

        $where = empty($conditions)
            ? ''
            : 'WHERE ' . implode("\nAND ", $conditions);

        $allowed = [
            'id',
            'business_name',
            'owner_name',
            'status',
        ];

        if (!in_array($orderBy, $allowed, true)) {
            $orderBy = 'id';
        }

        $direction = strtoupper($direction);

        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'DESC';
        }

        $sql = sprintf(
            'SELECT *
             FROM %s
             %s
             ORDER BY %s %s
             LIMIT %%d OFFSET %%d',
            $this->table($this->table),
            $where,
            $orderBy,
            $direction
        );

        $params[] = $perPage;
        $params[] = $offset;

        $rows = $this->db()->get_results(
            $this->db()->prepare(
                $sql,
                ...$params
            ),
            ARRAY_A
        );

        $collection = new Collection();

        foreach ($rows as $row) {
            $collection->add($this->hydrate($row));
        }

        return $collection;
    }

    /**
     * Cuenta los registros.
     */
    public function count(
        ?string $term = null,
        ?string $status = null
    ): int
    {
        $conditions = [];
        $params = [];

        if (!empty($term)) {

            $conditions[] = '(
                business_name LIKE %s
                OR owner_name LIKE %s
                OR email LIKE %s
                OR phone LIKE %s
            )';

            $term = '%' . $this->db()->esc_like($term) . '%';

            $params = [
                $term,
                $term,
                $term,
                $term,
            ];
        }

        if (!empty($status)) {
            $conditions[] = 'status = %s';
            $params[] = $status;
        }

        $where = empty($conditions)
            ? ''
            : 'WHERE ' . implode("\nAND ", $conditions);

        $sql = sprintf(
            'SELECT COUNT(*) FROM %s %s',
            $this->table($this->table),
            $where
        );

        if (empty($params)) {
            return (int) $this->db()->get_var($sql);
        }

        return (int) $this->db()->get_var(
            $this->db()->prepare(
                $sql,
                ...$params
            )
        );
    }
}
