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

    public function findItemByInventoryForSession(
        string $sessionId,
        int $inventoryId
    ): ?array {
        return $this->findItemByOwner(
            'session_id',
            $sessionId,
            $inventoryId
        );
    }

    public function findItemByInventoryForUser(
        int $userId,
        int $inventoryId
    ): ?array {
        return $this->findItemByOwner(
            'user_id',
            $userId,
            $inventoryId
        );
    }

    public function findInventorySnapshot(int $inventoryId): ?array
    {
        $row = $this->db()->get_row(
            $this->db()->prepare(
                sprintf(
                    'SELECT id, product_id, minimarket_id, price
                     FROM %s
                     WHERE id = %%d
                     LIMIT 1',
                    $this->table('inventory')
                ),
                $inventoryId
            ),
            ARRAY_A
        );

        return $row === null ? null : $row;
    }

    public function incrementQuantity(int $id, int $quantity): bool
    {
        $result = $this->db()->query(
            $this->db()->prepare(
                sprintf(
                    'UPDATE %s
                     SET quantity = quantity + %%d,
                         updated_at = %%s
                     WHERE id = %%d',
                    $this->table(self::TABLE)
                ),
                $quantity,
                current_time('mysql'),
                $id
            )
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible incrementar el item del carrito.'
            );
        }

        return $result === 1;
    }

    public function updateQuantity(
        int $id,
        int $quantity,
        ?string $sessionId,
        ?int $userId
    ): bool {
        $result = $this->db()->update(
            $this->table(self::TABLE),
            [
                'quantity' => $quantity,
                'updated_at' => current_time('mysql'),
            ],
            $this->ownerWhere($id, $sessionId, $userId)
        );

        if ($result === false) {
            throw new PersistenceException(
                'No fue posible actualizar el item del carrito.'
            );
        }

        return $result > 0;
    }

    public function delete(
        int $id,
        ?string $sessionId,
        ?int $userId
    ): bool {
        $result = $this->db()->delete(
            $this->table(self::TABLE),
            $this->ownerWhere($id, $sessionId, $userId)
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

    private function findItemByOwner(
        string $field,
        string|int $owner,
        int $inventoryId
    ): ?array {
        $ownerPlaceholder = is_int($owner) ? '%d' : '%s';
        $sql = sprintf(
            'SELECT *
             FROM %s
             WHERE %s = %s
               AND inventory_id = %%d
             LIMIT 1',
            $this->table(self::TABLE),
            $field,
            $ownerPlaceholder
        );
        $row = $this->db()->get_row(
            $this->db()->prepare($sql, $owner, $inventoryId),
            ARRAY_A
        );

        return $row === null ? null : $row;
    }

    private function ownerWhere(
        int $id,
        ?string $sessionId,
        ?int $userId
    ): array {
        return $userId !== null
            ? ['id' => $id, 'user_id' => $userId]
            : ['id' => $id, 'session_id' => $sessionId];
    }
}
