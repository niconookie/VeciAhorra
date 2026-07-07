<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Reservations\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

final class ReservationRepository extends Repository
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
}
