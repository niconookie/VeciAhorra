<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Reservations\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

class ReservationRepository extends Repository
{
    private const TABLE = 'reservations';

    private const FIELDS = [
        'order_id', 'inventory_id', 'product_id', 'minimarket_id',
        'quantity', 'status', 'reserved_at', 'expires_at', 'released_at',
        'created_at', 'updated_at',
    ];

    public function create(array $data): int
    {
        $result = $this->db()->insert(
            $this->table(self::TABLE),
            array_intersect_key($data, array_flip(self::FIELDS))
        );

        if ($result === false || (int) $this->db()->insert_id <= 0) {
            throw new PersistenceException('No fue posible crear la reserva.');
        }

        return (int) $this->db()->insert_id;
    }

    public function find(int $id): ?array
    {
        $row = $this->db()->get_row(
            $this->db()->prepare(
                sprintf('SELECT * FROM %s WHERE id = %%d LIMIT 1', $this->table(self::TABLE)),
                $id
            ),
            ARRAY_A
        );

        return $row === null ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function findByOrderId(int $orderId): array
    {
        return $this->db()->get_results(
            $this->db()->prepare(
                sprintf('SELECT * FROM %s WHERE order_id = %%d ORDER BY id ASC', $this->table(self::TABLE)),
                $orderId
            ),
            ARRAY_A
        );
    }

    /** @param list<int> $orderIds */
    public function findByOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $placeholders = implode(
            ', ',
            array_fill(0, count($orderIds), '%d')
        );

        return $this->db()->get_results(
            $this->db()->prepare(
                sprintf(
                    'SELECT *
                     FROM %s
                     WHERE order_id IN (%s)
                     ORDER BY id ASC',
                    $this->table(self::TABLE),
                    $placeholders
                ),
                ...$orderIds
            ),
            ARRAY_A
        );
    }

    public function findByOrderIdsForUpdate(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        if ((int) $this->db()->get_var('SELECT @@in_transaction') !== 1) {
            throw new PersistenceException(
                'El lock de Reservations requiere una transaccion activa.'
            );
        }

        $placeholders = implode(', ', array_fill(0, count($orderIds), '%d'));

        $database = $this->db();
        $rows = $database->get_results($database->prepare(
            sprintf(
                'SELECT * FROM %s WHERE order_id IN (%s)'
                . ' ORDER BY id ASC FOR UPDATE',
                $this->table(self::TABLE),
                $placeholders
            ),
            ...$orderIds
        ), ARRAY_A);

        if ($database->last_error !== '') {
            throw new PersistenceException(
                'No fue posible bloquear las Reservations.'
            );
        }

        return $rows;
    }

    public function matchOrderItems(array $reservations, array $orderIds): bool
    {
        if ($reservations === [] || $orderIds === []) {
            return false;
        }

        $placeholders = implode(', ', array_fill(0, count($orderIds), '%d'));
        $items = $this->db()->get_results($this->db()->prepare(
            sprintf(
                'SELECT order_id, inventory_id, product_id, quantity'
                . ' FROM %s WHERE order_id IN (%s)'
                . ' ORDER BY order_id ASC, id ASC',
                $this->table('order_items'),
                $placeholders
            ),
            ...$orderIds
        ), ARRAY_A);
        $normalize = static function (array $rows): array {
            $values = array_map(
                static fn (array $row): string => implode(':', [
                    (int) $row['order_id'],
                    (int) $row['inventory_id'],
                    (int) $row['product_id'],
                    (int) $row['quantity'],
                ]),
                $rows
            );
            sort($values, SORT_STRING);

            return $values;
        };

        return $items !== []
            && $normalize($items) === $normalize($reservations);
    }

    /** @param list<int> $ids */
    public function markConsumed(array $ids, string $updatedAt): int
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
                $this->table(self::TABLE),
                $placeholders
            );
        $params = ['consumed', $updatedAt, ...$ids, 'active'];
        $result = $this->db()->query(
            $this->db()->prepare($sql, ...$params)
        );

        if ($result === false || $result !== count($ids)) {
            throw new PersistenceException(
                'No fue posible consumir las reservas.'
            );
        }

        return $result;
    }

    public function deleteByOrderId(int $orderId): void
    {
        $result = $this->db()->delete(
            $this->table(self::TABLE),
            ['order_id' => $orderId]
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible eliminar las reservas incompletas.'
            );
        }
    }

    /** @param list<int> $ids */
    public function deleteByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $sql = sprintf(
            'DELETE FROM %s WHERE id IN (%s)',
            $this->table(self::TABLE),
            $placeholders
        );
        $result = $this->db()->query(
            $this->db()->prepare($sql, ...$ids)
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible eliminar las reservas incompletas.'
            );
        }
    }

    /** @param list<int> $ids */
    public function assignOrder(
        array $ids,
        int $orderId,
        string $updatedAt
    ): void {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $sql = sprintf(
            'UPDATE %s
             SET order_id = %%d,
                 updated_at = %%s
             WHERE id IN (%s)
               AND order_id IS NULL',
            $this->table(self::TABLE),
            $placeholders
        );
        $result = $this->db()->query(
            $this->db()->prepare(
                $sql,
                $orderId,
                $updatedAt,
                ...$ids
            )
        );

        if ($result === false || $result !== count($ids)) {
            throw new PersistenceException(
                'No fue posible asociar las reservas al pedido.'
            );
        }
    }

    /** @return list<array<string, mixed>> */
    public function findExpiredActive(string $now): array
    {
        return $this->db()->get_results(
            $this->db()->prepare(
                sprintf(
                    'SELECT *
                     FROM %s
                     WHERE status = %%s
                       AND expires_at <= %%s
                     ORDER BY id ASC',
                    $this->table(self::TABLE)
                ),
                'active',
                $now
            ),
            ARRAY_A
        );
    }

    public function markExpired(int $id, string $releasedAt): bool
    {
        $result = $this->db()->query(
            $this->db()->prepare(
                sprintf(
                    'UPDATE %s
                     SET status = %%s,
                         released_at = %%s,
                         updated_at = %%s
                     WHERE id = %%d
                       AND status = %%s',
                    $this->table(self::TABLE)
                ),
                'expired',
                $releasedAt,
                $releasedAt,
                $id,
                'active'
            )
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible expirar la reserva.'
            );
        }

        return $result === 1;
    }

    public function restoreActive(int $id, string $updatedAt): bool
    {
        $result = $this->db()->query(
            $this->db()->prepare(
                sprintf(
                    'UPDATE %s
                     SET status = %%s,
                         released_at = NULL,
                         updated_at = %%s
                     WHERE id = %%d
                       AND status = %%s',
                    $this->table(self::TABLE)
                ),
                'active',
                $updatedAt,
                $id,
                'expired'
            )
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible restaurar la reserva.'
            );
        }

        return $result === 1;
    }
}
