<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Fulfillment\Completion\Repository;

use Throwable;
use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;

final class FulfillmentCompletionRepository extends Repository
{
    public const DEFAULT_LEASE_SECONDS = 600;
    private const TABLE = 'fulfillment_completions';

    public static function ownerId(): string
    {
        return 'worker_' . bin2hex(random_bytes(16));
    }

    public function ensure(int $businessCompletionId): array
    {
        $key = hash('sha256', 'fulfillment-completion-v1|' . $businessCompletionId);
        $db = $this->db();
        $previous = $db->suppress_errors(true);
        try {
            $db->query($db->prepare(sprintf(
                'INSERT INTO %s (business_completion_id, idempotency_key, completion_status, created_at, updated_at)'
                . ' VALUES (%%d, %%s, %%s, UTC_TIMESTAMP(), UTC_TIMESTAMP())', $this->table(self::TABLE)
            ), $businessCompletionId, $key, 'pending'));
        } finally {
            $db->suppress_errors($previous);
        }
        $row = $this->findByBusinessCompletion($businessCompletionId);
        if ($row === null || ! hash_equals((string) $row['idempotency_key'], $key)) {
            throw new PersistenceException('FulfillmentCompletion no es coherente.');
        }
        return $row;
    }

    public function findByBusinessCompletion(int $businessCompletionId): ?array
    {
        $row = $this->db()->get_row($this->db()->prepare(sprintf(
            'SELECT *, UTC_TIMESTAMP() AS database_now,'
            . ' CASE WHEN lease_expires_at > UTC_TIMESTAMP() THEN 1 ELSE 0 END AS lease_active'
            . ' FROM %s WHERE business_completion_id = %%d LIMIT 1', $this->table(self::TABLE)
        ), $businessCompletionId), ARRAY_A);
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
        if ($updated === false) { throw new PersistenceException('No fue posible adquirir FulfillmentCompletion.'); }
        if ($updated !== 1) { return null; }
        $row = $this->findById($id);
        if ($row === null || ($row['lease_owner'] ?? null) !== $owner) {
            throw new PersistenceException('El lease FulfillmentCompletion no es coherente.');
        }
        return $row;
    }

    /** @return 'renewed'|'expired'|'lost' */
    public function renew(int $id, string $owner, int $version, int $seconds = self::DEFAULT_LEASE_SECONDS): string
    {
        $this->assertOwner($owner); $this->assertVersion($version); $this->assertDuration($seconds);
        $updated = $this->db()->query($this->db()->prepare(sprintf(
            'UPDATE %s SET lease_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %%d SECOND), updated_at = UTC_TIMESTAMP()'
            . ' WHERE id = %%d AND completion_status = %%s AND lease_owner = %%s AND lease_version = %%d'
            . ' AND lease_expires_at > UTC_TIMESTAMP()', $this->table(self::TABLE)
        ), $seconds, $id, 'processing', $owner, $version));
        if ($updated === false) { throw new PersistenceException('No fue posible renovar FulfillmentCompletion.'); }
        if ($updated === 1) { return 'renewed'; }
        $row = $this->findById($id);
        if ($row !== null && ($row['lease_owner'] ?? null) === $owner
            && (int) $row['lease_version'] === $version && ($row['completion_status'] ?? null) === 'processing') {
            return (int) $row['lease_active'] === 1 ? 'renewed' : 'expired';
        }
        return 'lost';
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->db()->query('START TRANSACTION') === false) {
            throw new PersistenceException('No se inicio FulfillmentCompletion.');
        }
        try {
            $result = $callback();
            if ($this->db()->query('COMMIT') === false) {
                throw new PersistenceException('Commit FulfillmentCompletion ambiguo.');
            }
            return $result;
        } catch (Throwable $error) {
            $this->db()->query('ROLLBACK');
            throw $error;
        }
    }

    public function lock(int $id, string $owner, int $version): ?array
    {
        $db = $this->db();
        $row = $db->get_row($db->prepare(sprintf(
            'SELECT * FROM %s WHERE id = %%d AND completion_status = %%s AND lease_owner = %%s'
            . ' AND lease_version = %%d AND lease_expires_at > UTC_TIMESTAMP() LIMIT 1 FOR UPDATE',
            $this->table(self::TABLE)
        ), $id, 'processing', $owner, $version), ARRAY_A);
        if ($db->last_error !== '') { throw new PersistenceException('No fue posible bloquear FulfillmentCompletion.'); }
        return $row === null ? null : $row;
    }

    public function lockBusinessCompletion(int $id): ?array
    {
        $db = $this->db();
        $row = $db->get_row($db->prepare(sprintf(
            'SELECT * FROM %s WHERE id = %%d LIMIT 1 FOR UPDATE', $this->table('business_completions')
        ), $id), ARRAY_A);
        if ($db->last_error !== '') { throw new PersistenceException('No fue posible bloquear BusinessCompletion.'); }
        return $row === null ? null : $row;
    }

    /** @return list<int> */
    public function lockSnapshotOrderIds(int $businessCompletionId): array
    {
        $db = $this->db();
        $rows = $db->get_col($db->prepare(sprintf(
            'SELECT order_id FROM %s WHERE business_completion_id = %%d ORDER BY order_id ASC FOR UPDATE',
            $this->table('business_completion_orders')
        ), $businessCompletionId));
        if ($db->last_error !== '') { throw new PersistenceException('No fue posible bloquear el snapshot fulfillment.'); }
        return array_map('intval', $rows);
    }

    public function lockDeliveryCompletion(int $businessCompletionId): ?array
    {
        $db = $this->db();
        $row = $db->get_row($db->prepare(sprintf(
            'SELECT * FROM %s WHERE business_completion_id = %%d LIMIT 1 FOR UPDATE',
            $this->table('delivery_completions')
        ), $businessCompletionId), ARRAY_A);
        if ($db->last_error !== '') { throw new PersistenceException('No fue posible bloquear DeliveryCompletion.'); }
        return $row === null ? null : $row;
    }

    /** @param list<int> $orderIds */
    public function lockDeliveryOrderIds(array $orderIds): array
    {
        if ($orderIds === []) { return []; }
        $placeholders = implode(', ', array_fill(0, count($orderIds), '%d'));
        $db = $this->db();
        $rows = $db->get_col($db->prepare(sprintf(
            'SELECT order_id FROM %s WHERE order_id IN (%s) ORDER BY order_id ASC FOR UPDATE',
            $this->table('deliveries'), $placeholders
        ), ...$orderIds));
        if ($db->last_error !== '') { throw new PersistenceException('No fue posible bloquear las Deliveries.'); }
        return array_map('intval', $rows);
    }

    public function close(int $id, string $owner, int $version, string $status, string $reason): bool
    {
        if (! in_array($status, ['completed', 'retryable', 'permanent_failure', 'manual_review'], true)) {
            throw new \InvalidArgumentException('Estado FulfillmentCompletion no valido.');
        }
        $completedSql = $status === 'completed' ? 'UTC_TIMESTAMP()' : 'NULL';
        $errorSql = $status === 'completed' ? 'NULL' : 'UTC_TIMESTAMP()';
        $updated = $this->db()->query($this->db()->prepare(sprintf(
            'UPDATE %s SET completion_status = %%s, last_result_code = %%s, last_error_at = %s,'
            . ' completed_at = %s, lease_owner = NULL, lease_acquired_at = NULL, lease_expires_at = NULL,'
            . ' updated_at = UTC_TIMESTAMP() WHERE id = %%d AND completion_status = %%s'
            . ' AND lease_owner = %%s AND lease_version = %%d AND lease_expires_at > UTC_TIMESTAMP()',
            $this->table(self::TABLE), $errorSql, $completedSql
        ), $status, $reason, $id, 'processing', $owner, $version));
        if ($updated === false) { throw new PersistenceException('No fue posible cerrar FulfillmentCompletion.'); }
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
        if (preg_match('/^worker_[a-f0-9]{32}$/D', $owner) !== 1) {
            throw new \InvalidArgumentException('lease_owner no valido.');
        }
    }

    private function assertVersion(int $version): void
    {
        if ($version <= 0) { throw new \InvalidArgumentException('lease_version no valido.'); }
    }

    private function assertDuration(int $seconds): void
    {
        if ($seconds < 1 || $seconds > 3600) { throw new \InvalidArgumentException('Duracion no valida.'); }
    }
}
