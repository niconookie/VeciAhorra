<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Repositories;

use VeciAhorra\Database\BaseRepository;
use VeciAhorra\Database\Collection;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Stores\Contracts\StoreTransitionRepositoryInterface;
use VeciAhorra\Modules\Stores\Models\Store;

/**
 * Repositorio de Minimarkets.
 */
final class StoreRepository extends BaseRepository implements StoreTransitionRepositoryInterface
{
    public function delete(int $id): int
    {
        throw new PersistenceException('La eliminacion Store requiere la politica de integridad referencial.');
    }

    public function compareAndSetLifecycle(
        int $id,
        array $expected,
        array $target,
        string $updatedAt
    ): int {
        $approvalSet = $target['approved_at'] === null
            ? 'approved_at = NULL'
            : 'approved_at = %s';
        $approvalWhere = $expected['approved_at'] === null
            ? 'approved_at IS NULL'
            : 'approved_at = %s';
        $params = [$target['status'], $target['onboarding_status']];
        if ($target['approved_at'] !== null) {
            $params[] = $target['approved_at'];
        }
        $params[] = $updatedAt;
        $params[] = $id;
        $params[] = $expected['status'];
        $params[] = $expected['onboarding_status'];
        if ($expected['approved_at'] !== null) {
            $params[] = $expected['approved_at'];
        }

        $sql = sprintf(
            'UPDATE %s SET status = %%s, onboarding_status = %%s, %s, updated_at = %%s'
            . ' WHERE id = %%d AND status = %%s AND onboarding_status = %%s AND %s',
            $this->table($this->table),
            $approvalSet,
            $approvalWhere
        );
        $database = $this->db();
        $result = $database->query($database->prepare($sql, ...$params));
        if ($result === false) {
            throw new PersistenceException('No fue posible aplicar la transicion Store.');
        }
        if ($result > 1) {
            throw new PersistenceException('La transicion Store afecto mas de un registro.');
        }

        return (int) $result;
    }

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

        $orderClause = $orderBy === 'id'
            ? sprintf('id %s', $direction)
            : sprintf('%s %s, id ASC', $orderBy, $direction);

        $sql = sprintf(
            'SELECT *
             FROM %s
             %s
             ORDER BY %s
             LIMIT %%d OFFSET %%d',
            $this->table($this->table),
            $where,
            $orderClause
        );

        $params[] = $perPage;
        $params[] = $offset;

        $database = $this->db();
        $rows = $database->get_results(
            $database->prepare(
                $sql,
                ...$params
            ),
            ARRAY_A
        );

        if (! is_array($rows) || $database->last_error !== '') {
            throw new PersistenceException(
                'No fue posible listar los minimarkets.'
            );
        }

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

        $database = $this->db();
        $value = empty($params)
            ? $database->get_var($sql)
            : $database->get_var(
                $database->prepare(
                    $sql,
                    ...$params
                )
            );

        if ($value === null || $database->last_error !== '') {
            throw new PersistenceException(
                'No fue posible contar los minimarkets.'
            );
        }

        return (int) $value;
    }

    public function paginateAdmin(
        int $page,
        int $perPage,
        ?string $term,
        ?string $status,
        ?string $lifecycleState,
        string $orderBy,
        string $direction
    ): Collection {
        [$where, $params] = $this->adminWhere($term, $status, $lifecycleState);
        $allowed = ['business_name', 'created_at', 'updated_at'];
        if (! in_array($orderBy, $allowed, true)) {
            $orderBy = 'business_name';
        }
        $direction = in_array($direction, ['ASC', 'DESC'], true) ? $direction : 'ASC';
        $sql = sprintf(
            'SELECT * FROM %s %s ORDER BY %s %s, id ASC LIMIT %%d OFFSET %%d',
            $this->table($this->table),
            $where,
            $orderBy,
            $direction
        );
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;
        $database = $this->db();
        $rows = $database->get_results($database->prepare($sql, ...$params), ARRAY_A);
        if (! is_array($rows) || $database->last_error !== '') {
            throw new PersistenceException('No fue posible listar los minimarkets.');
        }
        $collection = new Collection();
        foreach ($rows as $row) {
            $collection->add($this->hydrate($row));
        }

        return $collection;
    }

    public function countAdmin(?string $term, ?string $status, ?string $lifecycleState): int
    {
        [$where, $params] = $this->adminWhere($term, $status, $lifecycleState);
        $sql = sprintf('SELECT COUNT(*) FROM %s %s', $this->table($this->table), $where);
        $database = $this->db();
        $value = $params === []
            ? $database->get_var($sql)
            : $database->get_var($database->prepare($sql, ...$params));
        if ($value === null || $database->last_error !== '') {
            throw new PersistenceException('No fue posible contar los minimarkets.');
        }

        return (int) $value;
    }

    /** @return array{0:string,1:list<mixed>} */
    private function adminWhere(?string $term, ?string $status, ?string $lifecycleState): array
    {
        $conditions = [];
        $params = [];
        if ($term !== null && $term !== '') {
            $like = '%' . $this->db()->esc_like($term) . '%';
            $conditions[] = '(business_name LIKE %s OR legal_name LIKE %s OR rut LIKE %s OR email LIKE %s OR commune LIKE %s OR city LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if ($status !== null) {
            $conditions[] = 'status = %s';
            $params[] = $status;
        }
        if ($lifecycleState !== null) {
            $conditions[] = $this->lifecyclePredicate($lifecycleState);
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    private function lifecyclePredicate(string $state): string
    {
        $unapproved = "(approved_at IS NULL OR approved_at = '')";
        $approved = "approved_at IS NOT NULL AND approved_at <> ''"
            . " AND approved_at <> '0000-00-00 00:00:00'"
            . " AND approved_at REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$'";
        $valid = "((status = 'pending' AND onboarding_status = 'draft' AND {$unapproved})"
            . " OR (status = 'pending' AND onboarding_status = 'complete' AND {$unapproved})"
            . " OR (status = 'rejected' AND onboarding_status = 'complete' AND {$unapproved})"
            . " OR (status = 'inactive' AND onboarding_status = 'complete' AND {$approved})"
            . " OR (status = 'active' AND onboarding_status = 'complete' AND {$approved}))";
        return match ($state) {
            'draft' => "(status = 'pending' AND onboarding_status = 'draft' AND {$unapproved})",
            'in_review' => "(status = 'pending' AND onboarding_status = 'complete' AND {$unapproved})",
            'rejected' => "(status = 'rejected' AND onboarding_status = 'complete' AND {$unapproved})",
            'approved_inactive' => "(status = 'inactive' AND onboarding_status = 'complete' AND {$approved})",
            'active' => "(status = 'active' AND onboarding_status = 'complete' AND {$approved})",
            'invalid' => "NOT ({$valid}) OR status IS NULL OR onboarding_status IS NULL",
            default => '1 = 0',
        };
    }
}
