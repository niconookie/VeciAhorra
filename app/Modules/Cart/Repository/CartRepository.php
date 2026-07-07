<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Cart\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

final class CartRepository extends Repository
{
    private const TABLE = 'cart_items';

    private const FIELDS = [
        'session_id', 'user_id', 'inventory_id', 'product_id',
        'minimarket_id', 'quantity', 'unit_price_snapshot',
        'created_at', 'updated_at',
    ];

    public function create(array $data): int
    {
        $result = $this->db()->insert(
            $this->table(self::TABLE),
            array_intersect_key($data, array_flip(self::FIELDS))
        );

        if ($result === false || (int) $this->db()->insert_id <= 0) {
            throw new PersistenceException(
                'No fue posible crear el item del carrito.'
            );
        }

        return (int) $this->db()->insert_id;
    }

    /** @return list<array<string, mixed>> */
    public function findBySession(string $sessionId): array
    {
        return $this->findBy('session_id', $sessionId);
    }

    /** @return list<array<string, mixed>> */
    public function findByUser(int $userId): array
    {
        return $this->findBy('user_id', $userId);
    }

    public function updateQuantity(int $id, int $quantity): bool
    {
        $result = $this->db()->update(
            $this->table(self::TABLE),
            [
                'quantity' => $quantity,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id]
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible actualizar el item del carrito.'
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
                'No fue posible eliminar el item del carrito.'
            );
        }

        return $result > 0;
    }

    public function clear(?string $sessionId, ?int $userId): int
    {
        $where = $userId !== null
            ? ['user_id' => $userId]
            : ['session_id' => $sessionId];
        $result = $this->db()->delete(
            $this->table(self::TABLE),
            $where
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible limpiar el carrito.'
            );
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function findBy(string $field, string|int $value): array
    {
        $placeholder = is_int($value) ? '%d' : '%s';
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = %s ORDER BY id ASC',
            $this->table(self::TABLE),
            $field,
            $placeholder
        );

        return $this->db()->get_results(
            $this->db()->prepare($sql, $value),
            ARRAY_A
        );
    }
}
