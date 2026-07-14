<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Repository;

use Throwable;
use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

final class CheckoutRepository extends Repository
{
    private const TABLE = 'checkouts';

    public function transaction(callable $callback): mixed
    {
        $nested = (int) $this->db()->get_var('SELECT @@in_transaction') === 1;
        $savepoint = 'va_checkout_' . substr(hash(
            'sha256',
            (string) microtime(true) . random_int(1, PHP_INT_MAX)
        ), 0, 12);

        if ($nested) {
            if ($this->db()->query("SAVEPOINT {$savepoint}") === false) {
                throw new PersistenceException(
                    'No fue posible crear el savepoint de la transaccion.'
                );
            }
        } elseif ($this->db()->query('START TRANSACTION') === false) {
            throw new PersistenceException('No fue posible iniciar la transaccion.');
        }

        try {
            $result = $callback();

            $statement = $nested ? "RELEASE SAVEPOINT {$savepoint}" : 'COMMIT';

            if ($this->db()->query($statement) === false) {
                throw new PersistenceException('No fue posible confirmar la transaccion.');
            }

            return $result;
        } catch (Throwable $exception) {
            $this->db()->query(
                $nested ? "ROLLBACK TO SAVEPOINT {$savepoint}" : 'ROLLBACK'
            );
            throw $exception;
        }
    }

    public function create(array $data): int
    {
        $result = $this->db()->insert($this->table(self::TABLE), $data);

        if ($result === false || (int) $this->db()->insert_id <= 0) {
            throw new PersistenceException('No fue posible crear el checkout.');
        }

        return (int) $this->db()->insert_id;
    }

    public function findOwnedByPublicId(
        string $publicId,
        array $owner,
        bool $forUpdate = false
    ): ?array {
        [$field, $value, $placeholder] = $this->ownerCondition($owner);
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf(
                'SELECT * FROM %s WHERE public_id = %%s AND owner_type = %%s'
                . ' AND %s = %s LIMIT 1%s',
                $this->table(self::TABLE),
                $field,
                $placeholder,
                $forUpdate ? ' FOR UPDATE' : ''
            ),
            $publicId,
            $owner['owner_type'],
            $value
        ), ARRAY_A);

        return $row === null ? null : $row;
    }

    public function find(int $id): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf('SELECT * FROM %s WHERE id = %%d LIMIT 1', $this->table(self::TABLE)),
            $id
        ), ARRAY_A);

        return $row === null ? null : $row;
    }

    public function findByIdempotency(string $ownerKey, string $key): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf('SELECT * FROM %s WHERE idempotency_owner_key = %%s AND idempotency_key = %%s LIMIT 1', $this->table(self::TABLE)),
            $ownerKey,
            $key
        ), ARRAY_A);
        return $row === null ? null : $row;
    }

    public function findByPublicIdForUpdate(string $publicId): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf('SELECT * FROM %s WHERE public_id = %%s LIMIT 1 FOR UPDATE', $this->table(self::TABLE)),
            $publicId
        ), ARRAY_A);
        return $row === null ? null : $row;
    }

    public function updateStatus(
        int $id,
        string $expected,
        string $status,
        string $updatedAt
    ): bool {
        $result = $this->db()->query($this->db()->prepare(
            sprintf(
                'UPDATE %s SET status = %%s, updated_at = %%s'
                . ' WHERE id = %%d AND status = %%s',
                $this->table(self::TABLE)
            ),
            $status,
            $updatedAt,
            $id,
            $expected
        ));

        if ($result === false) {
            throw new PersistenceException('No fue posible actualizar el checkout.');
        }

        return $result === 1;
    }

    private function ownerCondition(array $owner): array
    {
        return $owner['owner_type'] === 'user'
            ? ['user_id', (int) $owner['user_id'], '%d']
            : ['session_id', (string) $owner['session_id'], '%s'];
    }
}
