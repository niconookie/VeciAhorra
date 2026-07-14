<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\BusinessCompletion\Repository;

use Throwable;
use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

final class BusinessCompletionRepository extends Repository
{
    private const TABLE = 'business_completions';

    public function ensure(int $reconciliationId, string $key, string $now): array
    {
        $db = $this->db();
        $previous = $db->suppress_errors(true);
        try {
            $db->insert($this->table(self::TABLE), [
                'reconciliation_id' => $reconciliationId, 'idempotency_key' => $key,
                'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
            ]);
        } finally {
            $db->suppress_errors($previous);
        }
        $row = $this->findByReconciliation($reconciliationId);
        if ($row === null || ! hash_equals((string) $row['idempotency_key'], $key)) {
            throw new PersistenceException('La autoridad de finalizacion es incompatible.');
        }
        return $row;
    }

    public function findByReconciliation(int $id): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf('SELECT * FROM %s WHERE reconciliation_id = %%d LIMIT 1', $this->table(self::TABLE)), $id
        ), ARRAY_A);
        return $row === null ? null : $row;
    }

    public function acquire(int $id, string $owner, string $now, string $expires): ?array
    {
        $result = $this->db()->query($this->db()->prepare(sprintf(
            'UPDATE %s SET status = %%s, lease_owner = %%s, lease_acquired_at = %%s,'
            . ' lease_expires_at = %%s, lease_version = lease_version + 1,'
            . ' attempt_count = attempt_count + 1, updated_at = %%s'
            . ' WHERE id = %%d AND (status IN (%%s, %%s)'
            . ' OR (status = %%s AND lease_expires_at < %%s))',
            $this->table(self::TABLE)
        ), 'processing', $owner, $now, $expires, $now, $id, 'pending', 'retryable', 'processing', $now));
        if ($result === false) {
            throw new PersistenceException('No fue posible adquirir el claim de finalizacion.');
        }
        $row = $this->findByReconciliationRowId($id);
        return $result === 1 && ($row['lease_owner'] ?? null) === $owner ? $row : null;
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->db()->query('START TRANSACTION') === false) {
            throw new PersistenceException('No fue posible iniciar la finalizacion.');
        }
        try {
            $value = $callback();
            if ($this->db()->query('COMMIT') === false) {
                throw new PersistenceException('El commit de finalizacion fue ambiguo.');
            }
            return $value;
        } catch (Throwable $e) {
            $this->db()->query('ROLLBACK');
            throw $e;
        }
    }

    public function lock(int $id, string $owner, int $version): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(sprintf(
            'SELECT * FROM %s WHERE id = %%d AND status = %%s AND lease_owner = %%s'
            . ' AND lease_version = %%d LIMIT 1 FOR UPDATE', $this->table(self::TABLE)
        ), $id, 'processing', $owner, $version), ARRAY_A);
        return $row === null ? null : $row;
    }

    public function lockReconciliation(int $id): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(sprintf(
            'SELECT * FROM %s WHERE id = %%d LIMIT 1 FOR UPDATE', $this->table('payment_reconciliations')
        ), $id), ARRAY_A);
        return $row === null ? null : $row;
    }

    public function complete(int $id, string $owner, int $version, int $paymentId, string $now): bool
    {
        $result = $this->db()->query($this->db()->prepare(sprintf(
            'UPDATE %s SET status = %%s, payment_id = %%d, lease_owner = NULL,'
            . ' lease_acquired_at = NULL, lease_expires_at = NULL,'
            . ' last_result_code = %%s, last_error_at = NULL, completed_at = %%s,'
            . ' updated_at = %%s WHERE id = %%d AND status = %%s'
            . ' AND lease_owner = %%s AND lease_version = %%d AND lease_expires_at >= %%s',
            $this->table(self::TABLE)
        ), 'completed', $paymentId, 'completed', $now, $now, $id, 'processing', $owner, $version, $now));
        if ($result === false) {
            throw new PersistenceException('No fue posible cerrar la finalizacion.');
        }
        return $result === 1;
    }

    public function fail(int $id, string $owner, int $version, string $status, string $reason, string $now): void
    {
        $this->transition($id, $owner, $version, $status, $reason, $now, null);
    }

    private function transition(int $id, string $owner, int $version, string $status, string $reason, string $now, ?int $paymentId): int
    {
        $completed = $status === 'completed' ? $now : null;
        $errorAt = $status === 'completed' ? null : $now;
        $result = $this->db()->update($this->table(self::TABLE), [
            'status' => $status, 'payment_id' => $paymentId,
            'lease_owner' => null, 'lease_acquired_at' => null, 'lease_expires_at' => null,
            'last_result_code' => $reason, 'last_error_at' => $errorAt,
            'completed_at' => $completed, 'updated_at' => $now,
        ], ['id' => $id, 'status' => 'processing', 'lease_owner' => $owner, 'lease_version' => $version]);
        if ($result === false) {
            throw new PersistenceException('No fue posible cerrar la finalizacion.');
        }
        return $result;
    }

    private function findByReconciliationRowId(int $id): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(
            sprintf('SELECT * FROM %s WHERE id = %%d LIMIT 1', $this->table(self::TABLE)), $id
        ), ARRAY_A);
        return $row === null ? null : $row;
    }
}
