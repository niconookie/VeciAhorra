<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Repository;

use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

final class PaymentSessionRepository extends Repository
{
    private const TABLE = 'payment_sessions';

    public function create(array $data): int
    {
        $result = $this->db()->insert($this->table(self::TABLE), $data);

        if ($result === false || (int) $this->db()->insert_id <= 0) {
            throw new PersistenceException('No fue posible crear la sesion de pago.');
        }

        return (int) $this->db()->insert_id;
    }

    public function find(int $id): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf('SELECT * FROM %s WHERE id = %%d LIMIT 1', $this->table(self::TABLE)),
            $id
        ), ARRAY_A);

        return $row === null ? null : $row;
    }

    public function findByKey(int $checkoutId, string $key): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf(
                'SELECT * FROM %s WHERE checkout_id = %%d'
                . ' AND idempotency_key = %%s LIMIT 1',
                $this->table(self::TABLE)
            ),
            $checkoutId,
            $key
        ), ARRAY_A);

        return $row === null ? null : $row;
    }

    public function findActive(int $checkoutId, string $now): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf(
                'SELECT * FROM %s WHERE checkout_id = %%d'
                . ' AND status IN (%%s, %%s) AND expires_at > %%s'
                . ' ORDER BY id DESC LIMIT 1',
                $this->table(self::TABLE)
            ),
            $checkoutId,
            'pending',
            'ready',
            $now
        ), ARRAY_A);

        return $row === null ? null : $row;
    }

    public function findOwnedByPublicId(string $publicId, array $owner): ?array
    {
        $ownerField = $owner['owner_type'] === 'user' ? 'user_id' : 'session_id';
        $placeholder = $owner['owner_type'] === 'user' ? '%d' : '%s';
        $value = $owner[$ownerField];
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf(
                'SELECT ps.*, c.public_id AS checkout_public_id'
                . ' FROM %s ps INNER JOIN %s c ON c.id = ps.checkout_id'
                . ' WHERE ps.public_id = %%s AND c.owner_type = %%s'
                . ' AND c.%s = %s LIMIT 1',
                $this->table(self::TABLE),
                $this->table('checkouts'),
                $ownerField,
                $placeholder
            ),
            $publicId,
            $owner['owner_type'],
            $value
        ), ARRAY_A);

        return $row === null ? null : $row;
    }
}
