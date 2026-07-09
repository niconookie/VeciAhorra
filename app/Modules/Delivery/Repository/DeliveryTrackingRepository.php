<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Delivery\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

/**
 * Persistencia de eventos de seguimiento de entregas.
 */
final class DeliveryTrackingRepository extends Repository
{
    private const TABLE = 'delivery_tracking';

    private const FIELDS = [
        'delivery_id',
        'latitude',
        'longitude',
        'event',
        'created_at',
    ];

    public function create(array $tracking): int
    {
        $result = $this->db()->insert(
            $this->table(self::TABLE),
            $this->only($tracking, self::FIELDS)
        );

        if ($result === false || (int) $this->db()->insert_id <= 0) {
            throw new PersistenceException(
                'No fue posible crear el evento de seguimiento.'
            );
        }

        return (int) $this->db()->insert_id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByDelivery(int $deliveryId): array
    {
        return $this->db()->get_results(
            $this->db()->prepare(
                sprintf(
                    'SELECT *
                     FROM %s
                     WHERE delivery_id = %%d
                     ORDER BY created_at ASC, id ASC',
                    $this->table(self::TABLE)
                ),
                $deliveryId
            ),
            ARRAY_A
        );
    }

    public function latestByDelivery(int $deliveryId): ?array
    {
        $row = $this->db()->get_row(
            $this->db()->prepare(
                sprintf(
                    'SELECT *
                     FROM %s
                     WHERE delivery_id = %%d
                     ORDER BY created_at DESC, id DESC
                     LIMIT 1',
                    $this->table(self::TABLE)
                ),
                $deliveryId
            ),
            ARRAY_A
        );

        return $row === null ? null : $row;
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
