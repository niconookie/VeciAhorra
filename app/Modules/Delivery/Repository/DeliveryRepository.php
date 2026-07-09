<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Delivery\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

/**
 * Persistencia base del modulo Delivery.
 */
final class DeliveryRepository extends Repository
{
    private const TABLE = 'deliveries';

    private const FIELDS = [
        'order_id',
        'customer_id',
        'minimarket_id',
        'status',
        'created_at',
        'updated_at',
    ];

    public function create(array $delivery): int
    {
        $result = $this->db()->insert(
            $this->table(self::TABLE),
            $this->only($delivery, self::FIELDS)
        );

        if ($result === false || (int) $this->db()->insert_id <= 0) {
            throw new PersistenceException(
                'No fue posible crear la entrega.'
            );
        }

        return (int) $this->db()->insert_id;
    }

    public function find(int $id): ?array
    {
        $row = $this->db()->get_row(
            $this->db()->prepare(
                sprintf(
                    'SELECT *
                     FROM %s
                     WHERE id = %%d
                     LIMIT 1',
                    $this->table(self::TABLE)
                ),
                $id
            ),
            ARRAY_A
        );

        return $row === null ? null : $row;
    }

    /**
     * @return array{
     *     data: list<array<string, mixed>>,
     *     pagination: array{page: int, per_page: int, total: int, total_pages: int}
     * }
     */
    public function paginate(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        [$where, $params] = $this->buildFilters($filters);
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db()->get_var(
            $this->db()->prepare(
                sprintf(
                    'SELECT COUNT(*)
                     FROM %s
                     WHERE %s',
                    $this->table(self::TABLE),
                    $whereSql
                ),
                ...$params
            )
        );

        $rows = $this->db()->get_results(
            $this->db()->prepare(
                sprintf(
                    'SELECT *
                     FROM %s
                     WHERE %s
                     ORDER BY id DESC
                     LIMIT %%d OFFSET %%d',
                    $this->table(self::TABLE),
                    $whereSql
                ),
                ...[...$params, $perPage, $offset]
            ),
            ARRAY_A
        );

        return [
            'data' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function exists(int $orderId): bool
    {
        return (int) $this->db()->get_var(
            $this->db()->prepare(
                sprintf(
                    'SELECT COUNT(*)
                     FROM %s
                     WHERE order_id = %%d',
                    $this->table(self::TABLE)
                ),
                $orderId
            )
        ) > 0;
    }

    public function updateStatus(
        int $id,
        string $status,
        string $updatedAt
    ): void {
        $result = $this->db()->update(
            $this->table(self::TABLE),
            [
                'status' => $status,
                'updated_at' => $updatedAt,
            ],
            ['id' => $id]
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible actualizar el estado de la entrega.'
            );
        }
    }

    /**
     * @return array{0: list<string>, 1: list<int|string>}
     */
    private function buildFilters(array $filters): array
    {
        $conditions = ['1 = %d'];
        $params = [1];

        foreach (
            [
                'order_id' => 'order_id = %d',
                'customer_id' => 'customer_id = %d',
                'minimarket_id' => 'minimarket_id = %d',
            ] as $field => $condition
        ) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $conditions[] = $condition;
                $params[] = (int) $filters[$field];
            }
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $conditions[] = 'status = %s';
            $params[] = (string) $filters['status'];
        }

        return [$conditions, $params];
    }

    /**
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    private function only(array $data, array $allowed): array
    {
        return array_intersect_key($data, array_flip($allowed));
    }
}
