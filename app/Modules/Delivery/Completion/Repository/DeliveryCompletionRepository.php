<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Delivery\Completion\Repository;

use Throwable;
use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

final class DeliveryCompletionRepository extends Repository
{
    public const DEFAULT_LEASE_SECONDS = 600;
    private const TABLE = 'delivery_completions';

    public function ensure(int $businessCompletionId): array
    {
        $key = hash('sha256', 'delivery-completion-v1|' . $businessCompletionId);
        $db = $this->db();
        $suppressed = $db->suppress_errors(true);
        try {
            $db->query($db->prepare(sprintf(
                'INSERT INTO %s (business_completion_id, idempotency_key, completion_status, created_at, updated_at)'
                . ' VALUES (%%d, %%s, %%s, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                $this->table(self::TABLE)
            ), $businessCompletionId, $key, 'pending'));
        } finally {
            $db->suppress_errors($suppressed);
        }
        $row = $this->findByBusinessCompletion($businessCompletionId);
        if ($row === null || ! hash_equals((string) $row['idempotency_key'], $key)) {
            throw new PersistenceException('DeliveryCompletion no es coherente.');
        }
        return $row;
    }

    public function findByBusinessCompletion(int $id): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(sprintf(
            'SELECT *, UTC_TIMESTAMP() AS database_now,'
            . ' CASE WHEN lease_expires_at > UTC_TIMESTAMP() THEN 1 ELSE 0 END AS lease_active'
            . ' FROM %s WHERE business_completion_id = %%d LIMIT 1', $this->table(self::TABLE)
        ), $id), ARRAY_A);
        return $row === null ? null : $row;
    }

    public function acquire(int $id, string $owner, int $seconds = self::DEFAULT_LEASE_SECONDS): ?array
    {
        $this->assertOwner($owner); $this->assertDuration($seconds);
        $updated = $this->db()->query($this->db()->prepare(sprintf(
            'UPDATE %s SET completion_status = %%s, lease_owner = %%s,'
            . ' lease_acquired_at = UTC_TIMESTAMP(), lease_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %%d SECOND),'
            . ' lease_version = lease_version + 1, attempt_count = attempt_count + 1, updated_at = UTC_TIMESTAMP()'
            . ' WHERE id = %%d AND (completion_status IN (%%s, %%s) OR'
            . ' (completion_status = %%s AND (lease_expires_at IS NULL OR lease_expires_at <= UTC_TIMESTAMP())))',
            $this->table(self::TABLE)
        ), 'processing', $owner, $seconds, $id, 'pending', 'retryable', 'processing'));
        if ($updated === false) { throw new PersistenceException('No fue posible adquirir el lease DeliveryCompletion.'); }
        if ($updated !== 1) { return null; }
        $row = $this->findById($id);
        return ($row['lease_owner'] ?? null) === $owner ? $row : null;
    }

    /** @return 'renewed'|'expired'|'lost' */
    public function renew(int $id, string $owner, int $version, int $seconds = self::DEFAULT_LEASE_SECONDS): string
    {
        $this->assertOwner($owner); $this->assertDuration($seconds);
        $updated = $this->db()->query($this->db()->prepare(sprintf(
            'UPDATE %s SET lease_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %%d SECOND), updated_at = UTC_TIMESTAMP()'
            . ' WHERE id = %%d AND completion_status = %%s AND lease_owner = %%s AND lease_version = %%d'
            . ' AND lease_expires_at > UTC_TIMESTAMP()', $this->table(self::TABLE)
        ), $seconds, $id, 'processing', $owner, $version));
        if ($updated === false) { throw new PersistenceException('No fue posible renovar el lease DeliveryCompletion.'); }
        if ($updated === 1) { return 'renewed'; }
        $row = $this->findById($id);
        if ($row !== null && ($row['lease_owner'] ?? null) === $owner && (int) $row['lease_version'] === $version && ($row['completion_status'] ?? null) === 'processing') {
            return (int) $row['lease_active'] === 1 ? 'renewed' : 'expired';
        }
        return 'lost';
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->db()->query('START TRANSACTION') === false) { throw new PersistenceException('No se inicio DeliveryCompletion.'); }
        try {
            $value = $callback();
            if ($this->db()->query('COMMIT') === false) { throw new PersistenceException('Commit DeliveryCompletion ambiguo.'); }
            return $value;
        } catch (Throwable $e) {
            $this->db()->query('ROLLBACK');
            throw $e;
        }
    }

    public function lock(int $id, string $owner, int $version): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(sprintf(
            'SELECT * FROM %s WHERE id = %%d AND completion_status = %%s AND lease_owner = %%s'
            . ' AND lease_version = %%d AND lease_expires_at > UTC_TIMESTAMP() LIMIT 1 FOR UPDATE',
            $this->table(self::TABLE)
        ), $id, 'processing', $owner, $version), ARRAY_A);
        return $row === null ? null : $row;
    }

    public function lockBusinessCompletion(int $id): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(sprintf(
            'SELECT * FROM %s WHERE id = %%d LIMIT 1 FOR UPDATE', $this->table('business_completions')
        ), $id), ARRAY_A);
        return $row === null ? null : $row;
    }

    public function snapshotOrderIds(int $businessCompletionId): array
    {
        return array_map('intval', $this->db()->get_col($this->db()->prepare(sprintf(
            'SELECT order_id FROM %s WHERE business_completion_id = %%d ORDER BY order_id ASC FOR UPDATE',
            $this->table('business_completion_orders')
        ), $businessCompletionId)));
    }

    public function close(int $id, string $owner, int $version, string $status, string $reason): bool
    {
        if (! in_array($status, ['completed', 'not_required', 'retryable', 'permanent_failure', 'manual_review'], true)) {
            throw new \InvalidArgumentException('Estado DeliveryCompletion no valido.');
        }
        $terminal = in_array($status, ['completed', 'not_required'], true);
        $completedSql = $terminal ? 'UTC_TIMESTAMP()' : 'NULL';
        $errorSql = $terminal ? 'NULL' : 'UTC_TIMESTAMP()';
        $updated = $this->db()->query($this->db()->prepare(sprintf(
            'UPDATE %s SET completion_status = %%s, last_result_code = %%s, last_error_at = %s,'
            . ' completed_at = %s, lease_owner = NULL, lease_acquired_at = NULL, lease_expires_at = NULL,'
            . ' updated_at = UTC_TIMESTAMP() WHERE id = %%d AND completion_status = %%s'
            . ' AND lease_owner = %%s AND lease_version = %%d AND lease_expires_at > UTC_TIMESTAMP()',
            $this->table(self::TABLE), $errorSql, $completedSql
        ), $status, $reason, $id, 'processing', $owner, $version));
        if ($updated === false) { throw new PersistenceException('No fue posible cerrar DeliveryCompletion.'); }
        return $updated === 1;
    }

    private function findById(int $id): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(sprintf(
            'SELECT *, CASE WHEN lease_expires_at > UTC_TIMESTAMP() THEN 1 ELSE 0 END AS lease_active'
            . ' FROM %s WHERE id = %%d LIMIT 1', $this->table(self::TABLE)
        ), $id), ARRAY_A);
        return $row === null ? null : $row;
    }

    private function assertOwner(string $owner): void
    {
        if (preg_match('/^worker_[a-f0-9]{32}$/D', $owner) !== 1) { throw new \InvalidArgumentException('lease_owner no valido.'); }
    }

    private function assertDuration(int $seconds): void
    {
        if ($seconds < 1 || $seconds > 3600) { throw new \InvalidArgumentException('Duracion de lease no valida.'); }
    }
}
