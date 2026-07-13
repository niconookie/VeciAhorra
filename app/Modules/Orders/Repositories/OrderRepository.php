<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Repositories;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

/**
 * Persistencia de pedidos y sus items.
 */
class OrderRepository extends Repository
{
    private const ORDERS_TABLE = 'orders';

    private const ITEMS_TABLE = 'order_items';

    private const ORDER_FIELDS = [
        'customer_id',
        'minimarket_id',
        'total',
        'status',
        'reservation_expires_at',
        'created_at',
        'updated_at',
    ];

    private const ITEM_FIELDS = [
        'product_id',
        'inventory_id',
        'quantity',
        'unit_price',
        'subtotal',
        'created_at',
        'updated_at',
    ];

    public function create(array $order): int
    {
        $result = $this->db()->insert(
            $this->table(self::ORDERS_TABLE),
            $this->only($order, self::ORDER_FIELDS)
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible crear el pedido.'
            );
        }

        $id = (int) $this->db()->insert_id;

        if ($id <= 0) {
            throw new PersistenceException(
                'No fue posible obtener el ID del pedido creado.'
            );
        }

        return $id;
    }

    public function createItems(int $orderId, array $items): void
    {
        foreach ($items as $item) {
            $payload = [
                'order_id' => $orderId,
                ...$this->only($item, self::ITEM_FIELDS),
            ];
            $result = $this->db()->insert(
                $this->table(self::ITEMS_TABLE),
                $payload
            );

            if ($result === false) {
                throw new PersistenceException(
                    'No fue posible crear los items del pedido.'
                );
            }
        }
    }

    public function find(int $id): ?array
    {
        $row = $this->db()->get_row(
            $this->db()->prepare(
                sprintf(
                    'SELECT * FROM %s WHERE id = %%d LIMIT 1',
                    $this->table(self::ORDERS_TABLE)
                ),
                $id
            ),
            ARRAY_A
        );

        return $row === null ? null : $row;
    }

    /** @param list<int> $ids */
    public function findManyForUpdate(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        $database = $this->db();
        $rows = $database->get_results($database->prepare(
            sprintf(
                'SELECT * FROM %s WHERE id IN (%s) ORDER BY id ASC FOR UPDATE',
                $this->table(self::ORDERS_TABLE),
                $placeholders
            ),
            ...$ids
        ), ARRAY_A);

        if ($database->last_error !== '') {
            throw new PersistenceException('No fue posible bloquear las Orders.');
        }

        return $rows;
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        return $this->db()->get_results($this->db()->prepare(
            sprintf(
                'SELECT * FROM %s WHERE id IN (%s) ORDER BY id ASC',
                $this->table(self::ORDERS_TABLE),
                $placeholders
            ),
            ...$ids
        ), ARRAY_A);
    }

    public function findForCustomer(int $id, int $customerId): ?array
    {
        $row = $this->db()->get_row(
            $this->db()->prepare(
                sprintf(
                    'SELECT *
                     FROM %s
                     WHERE id = %%d
                       AND customer_id = %%d
                     LIMIT 1',
                    $this->table(self::ORDERS_TABLE)
                ),
                $id,
                $customerId
            ),
            ARRAY_A
        );

        return $row === null ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function findItems(int $orderId): array
    {
        return $this->db()->get_results(
            $this->db()->prepare(
                sprintf(
                    'SELECT oi.*,
                            p.name AS product_name,
                            p.slug AS product_slug,
                            p.sku AS product_sku
                     FROM %s oi
                     LEFT JOIN %s p ON p.id = oi.product_id
                     WHERE oi.order_id = %%d
                     ORDER BY oi.id ASC',
                    $this->table(self::ITEMS_TABLE),
                    $this->table('products')
                ),
                $orderId
            ),
            ARRAY_A
        );
    }

    public function findSeller(int $minimarketId): ?array
    {
        $row = $this->db()->get_row(
            $this->db()->prepare(
                sprintf(
                    'SELECT id, business_name
                     FROM %s
                     WHERE id = %%d
                     LIMIT 1',
                    $this->table('stores')
                ),
                $minimarketId
            ),
            ARRAY_A
        );

        return $row === null ? null : $row;
    }

    public function delete(int $id): void
    {
        $itemsResult = $this->db()->delete(
            $this->table(self::ITEMS_TABLE),
            ['order_id' => $id]
        );
        $orderResult = $this->db()->delete(
            $this->table(self::ORDERS_TABLE),
            ['id' => $id]
        );

        if ($itemsResult === false || $orderResult === false) {
            throw new PersistenceException(
                'No fue posible eliminar el pedido incompleto.'
            );
        }
    }

    /** @param list<int> $ids */
    public function markPaid(array $ids, string $updatedAt): int
    {
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $sql = sprintf(
            'UPDATE %s
             SET status = %%s,
                 updated_at = %%s
             WHERE id IN (%s)
               AND status = %%s',
            $this->table(self::ORDERS_TABLE),
            $placeholders
        );
        $params = ['paid', $updatedAt, ...$ids, 'reserved'];
        $result = $this->db()->query(
            $this->db()->prepare($sql, ...$params)
        );

        if ($result === false || $result !== count($ids)) {
            throw new PersistenceException(
                'No fue posible marcar los pedidos como pagados.'
            );
        }

        return $result;
    }

    public function markDelivered(int $id, string $updatedAt): void
    {
        $result = $this->db()->update(
            $this->table(self::ORDERS_TABLE),
            [
                'status' => 'delivered',
                'updated_at' => $updatedAt,
            ],
            ['id' => $id]
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible marcar el pedido como entregado.'
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        [$where, $params] = $this->buildFilters($filters);
        $sql = sprintf(
            'SELECT *
             FROM %s
             WHERE %s
             ORDER BY id DESC',
            $this->table(self::ORDERS_TABLE),
            implode(' AND ', $where)
        );

        return $this->db()->get_results(
            $this->db()->prepare($sql, ...$params),
            ARRAY_A
        );
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
        return array_intersect_key(
            $data,
            array_flip($allowed)
        );
    }
}
