<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Couriers\Repository;

use VeciAhorra\Database\Repository;

/**
 * Consulta de repartidores para integraciones de Delivery.
 */
final class CourierRepository extends Repository
{
    private const TABLE = 'couriers';

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

    public function isApproved(array $courier): bool
    {
        if (isset($courier['status'])) {
            return (string) $courier['status'] === 'approved';
        }

        if (array_key_exists('approved_at', $courier)) {
            return $courier['approved_at'] !== null
                && $courier['approved_at'] !== '';
        }

        if (array_key_exists('is_approved', $courier)) {
            return (int) $courier['is_approved'] === 1;
        }

        return false;
    }
}
