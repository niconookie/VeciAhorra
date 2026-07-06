<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Repositories;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

/**
 * Persistencia del inventario del marketplace.
 */
final class InventoryRepository extends Repository
{
    private const TABLE = 'inventory';

    private const CREATE_FIELDS = [
        'product_id',
        'minimarket_id',
        'price',
        'stock',
        'status',
        'created_at',
        'updated_at',
    ];

    private const UPDATE_FIELDS = [
        'price',
        'stock',
        'status',
        'updated_at',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function paginate(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, (int) ($filters['per_page'] ?? 20));
        $offset = ($page - 1) * $perPage;
        [$where, $params] = $this->buildFilters($filters);

        $sql = sprintf(
            'SELECT *
             FROM %s
             %s
             ORDER BY id DESC
             LIMIT %%d OFFSET %%d',
            $this->table(self::TABLE),
            $where
        );
        $params[] = $perPage;
        $params[] = $offset;

        return $this->db()->get_results(
            $this->db()->prepare($sql, ...$params),
            ARRAY_A
        );
    }

    public function count(array $filters): int
    {
        [$where, $params] = $this->buildFilters($filters);
        $sql = sprintf(
            'SELECT COUNT(*) FROM %s %s',
            $this->table(self::TABLE),
            $where
        );

        if ($params !== []) {
            $sql = $this->db()->prepare($sql, ...$params);
        }

        return (int) $this->db()->get_var($sql);
    }

    public function find(int $id): ?array
    {
        $row = $this->db()->get_row(
            $this->db()->prepare(
                sprintf(
                    'SELECT * FROM %s WHERE id = %%d LIMIT 1',
                    $this->table(self::TABLE)
                ),
                $id
            ),
            ARRAY_A
        );

        return $row === null ? null : $row;
    }

    public function findByProductAndMinimarket(
        int $productId,
        int $minimarketId
    ): ?array {
        $row = $this->db()->get_row(
            $this->db()->prepare(
                sprintf(
                    'SELECT *
                     FROM %s
                     WHERE product_id = %%d
                       AND minimarket_id = %%d
                     LIMIT 1',
                    $this->table(self::TABLE)
                ),
                $productId,
                $minimarketId
            ),
            ARRAY_A
        );

        return $row === null ? null : $row;
    }

    public function create(array $data): int
    {
        $payload = $this->only($data, self::CREATE_FIELDS);
        $result = $this->db()->insert(
            $this->table(self::TABLE),
            $payload
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible crear el inventario.'
            );
        }

        $id = (int) $this->db()->insert_id;

        if ($id <= 0) {
            throw new PersistenceException(
                'No fue posible obtener el ID del inventario creado.'
            );
        }

        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $payload = $this->only($data, self::UPDATE_FIELDS);

        if ($payload === []) {
            return false;
        }

        $result = $this->db()->update(
            $this->table(self::TABLE),
            $payload,
            ['id' => $id]
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible actualizar el inventario.'
            );
        }

        return $result > 0;
    }

    public function delete(int $id): bool
    {
        $result = $this->db()->delete(
            $this->table(self::TABLE),
            ['id' => $id]
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible eliminar el inventario.'
            );
        }

        return $result > 0;
    }

    /**
     * @return array{0: string, 1: list<int|string>}
     */
    private function buildFilters(array $filters): array
    {
        $conditions = [];
        $params = [];

        foreach (['product_id', 'minimarket_id'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $conditions[] = "{$field} = %d";
                $params[] = (int) $filters[$field];
            }
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $conditions[] = 'status = %s';
            $params[] = (string) $filters['status'];
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $like = '%' . $this->db()->esc_like(
                (string) $filters['search']
            ) . '%';
            $conditions[] = '(
                CAST(id AS CHAR) LIKE %s
                OR CAST(product_id AS CHAR) LIKE %s
                OR CAST(minimarket_id AS CHAR) LIKE %s
                OR status LIKE %s
            )';
            array_push($params, $like, $like, $like, $like);
        }

        return [
            $conditions === []
                ? ''
                : 'WHERE ' . implode("\nAND ", $conditions),
            $params,
        ];
    }

    /**
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    private function only(array $data, array $allowed): array
    {
        return array_intersect_key(
            $data,
            array_flip($allowed)
        );
    }
}
