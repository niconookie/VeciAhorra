<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Repository;

use InvalidArgumentException;
use VeciAhorra\Database\Repository;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\LeaseAcquireResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\LeaseReleaseResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\LeaseRenewResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationLease;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationLeaseState;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\StatusTransitionResult;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Support\ReconciliationValidation;

final class PaymentReconciliationClaimRepository extends Repository
{
    public const DEFAULT_LEASE_SECONDS = 600;
    public const MAX_ATTEMPTS = 5;

    private const MIN_LEASE_SECONDS = 1;
    private const MAX_LEASE_SECONDS = 3600;
    private const TABLE = 'payment_reconciliations';

    public static function ownerId(): string
    {
        return 'worker_' . bin2hex(random_bytes(16));
    }

    public function acquireLease(
        int $reconciliationId,
        string $owner,
        mixed $durationSeconds = self::DEFAULT_LEASE_SECONDS
    ): LeaseAcquireResult {
        $this->assertId($reconciliationId);
        $this->assertOwner($owner);
        $duration = $this->duration($durationSeconds);
        $database = $this->db();
        $updated = $database->query($database->prepare(
            sprintf(
                'UPDATE %s SET reconciliation_status = %%s, lease_owner = %%s,'
                . ' lease_acquired_at = UTC_TIMESTAMP(),'
                . ' lease_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %%d SECOND),'
                . ' lease_version = lease_version + 1,'
                . ' attempt_count = attempt_count + 1,'
                . ' last_attempt_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()'
                . ' WHERE id = %%d AND attempt_count < %%d AND ('
                . ' reconciliation_status IN (%%s, %%s) OR ('
                . ' reconciliation_status = %%s AND ('
                . ' lease_expires_at IS NULL OR lease_expires_at <= UTC_TIMESTAMP())))',
                $this->table(self::TABLE)
            ),
            PaymentReconciliation::STATUS_PROCESSING,
            $owner,
            $duration,
            $reconciliationId,
            self::MAX_ATTEMPTS,
            PaymentReconciliation::STATUS_PENDING,
            PaymentReconciliation::STATUS_RETRYABLE,
            PaymentReconciliation::STATUS_PROCESSING
        ));

        if ($updated === false) {
            throw new PersistenceException('No fue posible adquirir el lease.');
        }

        $lease = $this->findLease($reconciliationId);

        if ($updated === 1) {
            if ($lease === null || $lease->owner() !== $owner) {
                throw new PersistenceException('El lease adquirido no es coherente.');
            }

            return new LeaseAcquireResult(
                LeaseAcquireResult::ACQUIRED,
                new ReconciliationLease(
                    $reconciliationId,
                    $owner,
                    $lease->version(),
                    (string) $lease->expiresAt()
                )
            );
        }

        if ($lease === null) {
            return new LeaseAcquireResult(LeaseAcquireResult::NOT_FOUND);
        }

        if (
            $lease->reconciliationStatus()
                === PaymentReconciliation::STATUS_PROCESSING
            && $lease->active()
        ) {
            return new LeaseAcquireResult(
                LeaseAcquireResult::BUSY
            );
        }

        if (
            $lease->attemptCount() >= self::MAX_ATTEMPTS
            && in_array($lease->reconciliationStatus(), [
                PaymentReconciliation::STATUS_PENDING,
                PaymentReconciliation::STATUS_RETRYABLE,
                PaymentReconciliation::STATUS_PROCESSING,
            ], true)
        ) {
            $this->markAttemptsExhausted($reconciliationId);

            return new LeaseAcquireResult(
                LeaseAcquireResult::ATTEMPTS_EXHAUSTED
            );
        }

        return new LeaseAcquireResult(LeaseAcquireResult::NOT_CLAIMABLE);
    }

    public function renewLease(
        int $reconciliationId,
        string $owner,
        int $leaseVersion,
        mixed $durationSeconds = self::DEFAULT_LEASE_SECONDS
    ): LeaseRenewResult {
        $this->assertId($reconciliationId);
        $this->assertOwner($owner);
        $this->assertVersion($leaseVersion);
        $duration = $this->duration($durationSeconds);
        $database = $this->db();
        $updated = $database->query($database->prepare(
            sprintf(
                'UPDATE %s SET lease_expires_at = DATE_ADD('
                . 'UTC_TIMESTAMP(), INTERVAL %%d SECOND),'
                . ' updated_at = UTC_TIMESTAMP() WHERE id = %%d'
                . ' AND reconciliation_status = %%s AND lease_owner = %%s'
                . ' AND lease_version = %%d'
                . ' AND lease_expires_at > UTC_TIMESTAMP()',
                $this->table(self::TABLE)
            ),
            $duration,
            $reconciliationId,
            PaymentReconciliation::STATUS_PROCESSING,
            $owner,
            $leaseVersion
        ));

        if ($updated === false) {
            throw new PersistenceException('No fue posible renovar el lease.');
        }

        $lease = $this->findLease($reconciliationId);

        if ($updated === 1) {
            return new LeaseRenewResult(
                LeaseRenewResult::RENEWED,
                $lease?->expiresAt()
            );
        }

        if ($lease === null) {
            return new LeaseRenewResult(LeaseRenewResult::NOT_FOUND);
        }

        if ($lease->reconciliationStatus() !== PaymentReconciliation::STATUS_PROCESSING) {
            return new LeaseRenewResult(LeaseRenewResult::INVALID_STATE);
        }

        if ($lease->owner() !== $owner) {
            return new LeaseRenewResult(LeaseRenewResult::NOT_OWNER);
        }

        if ($lease->version() !== $leaseVersion) {
            return new LeaseRenewResult(LeaseRenewResult::VERSION_MISMATCH);
        }

        if ($lease->active()) {
            return new LeaseRenewResult(
                LeaseRenewResult::RENEWED,
                $lease->expiresAt()
            );
        }

        return new LeaseRenewResult(LeaseRenewResult::EXPIRED, $lease->expiresAt());
    }

    public function releaseLease(
        int $reconciliationId,
        string $owner,
        int $leaseVersion
    ): LeaseReleaseResult {
        $this->assertId($reconciliationId);
        $this->assertOwner($owner);
        $this->assertVersion($leaseVersion);
        $database = $this->db();
        $updated = $database->query($database->prepare(
            sprintf(
                'UPDATE %s SET lease_owner = NULL, lease_acquired_at = NULL,'
                . ' lease_expires_at = NULL, updated_at = UTC_TIMESTAMP()'
                . ' WHERE id = %%d AND lease_owner = %%s'
                . ' AND lease_version = %%d',
                $this->table(self::TABLE)
            ),
            $reconciliationId,
            $owner,
            $leaseVersion
        ));

        if ($updated === false) {
            throw new PersistenceException('No fue posible liberar el lease.');
        }

        if ($updated === 1) {
            return new LeaseReleaseResult(LeaseReleaseResult::RELEASED);
        }

        $lease = $this->findLease($reconciliationId);

        if ($lease === null) {
            return new LeaseReleaseResult(LeaseReleaseResult::NOT_FOUND);
        }

        if ($lease->owner() === null) {
            return new LeaseReleaseResult(
                $lease->version() === $leaseVersion
                    ? LeaseReleaseResult::ALREADY_RELEASED
                    : LeaseReleaseResult::VERSION_MISMATCH
            );
        }

        if ($lease->owner() !== $owner) {
            return new LeaseReleaseResult(LeaseReleaseResult::NOT_OWNER);
        }

        return new LeaseReleaseResult(LeaseReleaseResult::VERSION_MISMATCH);
    }

    public function compareAndSetStatus(
        int $reconciliationId,
        string $owner,
        int $leaseVersion,
        string $expectedStatus,
        string $nextStatus,
        ?string $businessResultCode = null,
        ?string $lastErrorCode = null
    ): StatusTransitionResult {
        $this->assertId($reconciliationId);
        $this->assertOwner($owner);
        $this->assertVersion($leaseVersion);
        $this->assertTransition($expectedStatus, $nextStatus);
        $businessResultCode = ReconciliationValidation::nullableCode(
            $businessResultCode,
            'business_result_code',
            50
        );
        $lastErrorCode = ReconciliationValidation::nullableCode(
            $lastErrorCode,
            'last_error_code',
            50
        );
        $terminal = in_array($nextStatus, [
            PaymentReconciliation::STATUS_COMPLETED,
            PaymentReconciliation::STATUS_PERMANENT_FAILURE,
            PaymentReconciliation::STATUS_MANUAL_REVIEW,
        ], true);
        $assignments = [
            'reconciliation_status = %s',
            $businessResultCode === null
                ? 'business_result_code = NULL'
                : 'business_result_code = %s',
            $lastErrorCode === null
                ? 'last_error_code = NULL, last_error_at = NULL'
                : 'last_error_code = %s, last_error_at = UTC_TIMESTAMP()',
            $terminal
                ? 'reconciled_at = UTC_TIMESTAMP()'
                : 'reconciled_at = NULL',
            'lease_owner = NULL, lease_acquired_at = NULL, lease_expires_at = NULL',
            'updated_at = UTC_TIMESTAMP()',
        ];
        $parameters = [$nextStatus];

        if ($businessResultCode !== null) {
            $parameters[] = $businessResultCode;
        }

        if ($lastErrorCode !== null) {
            $parameters[] = $lastErrorCode;
        }

        array_push(
            $parameters,
            $reconciliationId,
            $expectedStatus,
            $owner,
            $leaseVersion
        );
        $database = $this->db();
        $updated = $database->query($database->prepare(
            sprintf(
                'UPDATE %s SET %s WHERE id = %%d'
                . ' AND reconciliation_status = %%s AND lease_owner = %%s'
                . ' AND lease_version = %%d'
                . ' AND lease_expires_at > UTC_TIMESTAMP()',
                $this->table(self::TABLE),
                implode(', ', $assignments)
            ),
            ...$parameters
        ));

        if ($updated === false) {
            throw new PersistenceException('No fue posible aplicar la transicion CAS.');
        }

        if ($updated === 1) {
            return new StatusTransitionResult(
                StatusTransitionResult::APPLIED,
                $nextStatus
            );
        }

        $lease = $this->findLease($reconciliationId);

        if ($lease === null) {
            return new StatusTransitionResult(StatusTransitionResult::NOT_FOUND);
        }

        if (
            $lease->version() !== $leaseVersion
            && ($lease->owner() === null || $lease->owner() === $owner)
        ) {
            return new StatusTransitionResult(
                StatusTransitionResult::VERSION_MISMATCH,
                $lease->reconciliationStatus()
            );
        }

        if (
            $lease->reconciliationStatus() === $nextStatus
            && $lease->version() === $leaseVersion
            && $lease->owner() === null
            && $lease->acquiredAt() === null
            && $lease->expiresAt() === null
            && $lease->businessResultCode() === $businessResultCode
            && $lease->lastErrorCode() === $lastErrorCode
            && (($lastErrorCode === null && $lease->lastErrorAt() === null)
                || ($lastErrorCode !== null && $lease->lastErrorAt() !== null))
            && (($terminal && $lease->reconciledAt() !== null)
                || (! $terminal && $lease->reconciledAt() === null))
        ) {
            return new StatusTransitionResult(
                StatusTransitionResult::ALREADY_APPLIED,
                $nextStatus
            );
        }

        if ($lease->reconciliationStatus() !== $expectedStatus) {
            return new StatusTransitionResult(
                StatusTransitionResult::UNEXPECTED_STATE,
                $lease->reconciliationStatus()
            );
        }

        if ($lease->owner() !== $owner) {
            return new StatusTransitionResult(
                StatusTransitionResult::NOT_OWNER,
                $lease->reconciliationStatus()
            );
        }

        if ($lease->version() !== $leaseVersion) {
            return new StatusTransitionResult(
                StatusTransitionResult::VERSION_MISMATCH,
                $lease->reconciliationStatus()
            );
        }

        return new StatusTransitionResult(
            StatusTransitionResult::LEASE_EXPIRED,
            $lease->reconciliationStatus()
        );
    }

    public function findLease(int $reconciliationId): ?ReconciliationLeaseState
    {
        $this->assertId($reconciliationId);
        $database = $this->db();
        $row = $database->get_row($database->prepare(
            sprintf(
                'SELECT id, reconciliation_status, attempt_count, lease_owner,'
                . ' lease_acquired_at, lease_expires_at, lease_version,'
                . ' business_result_code, last_error_code, last_error_at,'
                . ' reconciled_at,'
                . ' UTC_TIMESTAMP() AS database_now,'
                . ' CASE WHEN lease_expires_at > UTC_TIMESTAMP() THEN 1 ELSE 0 END'
                . ' AS lease_active FROM %s WHERE id = %%d LIMIT 1',
                $this->table(self::TABLE)
            ),
            $reconciliationId
        ), ARRAY_A);

        if ($database->last_error !== '') {
            throw new PersistenceException('No fue posible inspeccionar el lease.');
        }

        if ($row === null) {
            return null;
        }

        return new ReconciliationLeaseState(
            (int) $row['id'],
            (string) $row['reconciliation_status'],
            (int) $row['attempt_count'],
            isset($row['lease_owner']) ? (string) $row['lease_owner'] : null,
            isset($row['lease_acquired_at']) ? (string) $row['lease_acquired_at'] : null,
            isset($row['lease_expires_at']) ? (string) $row['lease_expires_at'] : null,
            (int) $row['lease_version'],
            (string) $row['database_now'],
            (int) $row['lease_active'] === 1,
            isset($row['business_result_code'])
                ? (string) $row['business_result_code']
                : null,
            isset($row['last_error_code'])
                ? (string) $row['last_error_code']
                : null,
            isset($row['last_error_at']) ? (string) $row['last_error_at'] : null,
            isset($row['reconciled_at']) ? (string) $row['reconciled_at'] : null
        );
    }

    private function duration(mixed $duration): int
    {
        if (
            ! is_int($duration)
            || $duration < self::MIN_LEASE_SECONDS
            || $duration > self::MAX_LEASE_SECONDS
        ) {
            throw new InvalidArgumentException('La duracion del lease no es valida.');
        }

        return $duration;
    }

    private function assertId(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('reconciliation_id no es valido.');
        }
    }

    private function assertOwner(string $owner): void
    {
        if (preg_match('/^worker_[a-f0-9]{32}$/D', $owner) !== 1) {
            throw new InvalidArgumentException('lease_owner no es valido.');
        }
    }

    private function assertVersion(int $version): void
    {
        if ($version <= 0) {
            throw new InvalidArgumentException('lease_version no es valido.');
        }
    }

    private function assertTransition(string $expected, string $next): void
    {
        if (
            $expected !== PaymentReconciliation::STATUS_PROCESSING
            || ! in_array($next, [
                PaymentReconciliation::STATUS_COMPLETED,
                PaymentReconciliation::STATUS_RETRYABLE,
                PaymentReconciliation::STATUS_PERMANENT_FAILURE,
                PaymentReconciliation::STATUS_MANUAL_REVIEW,
            ], true)
        ) {
            throw new InvalidArgumentException('La transicion CAS no es valida.');
        }
    }

    private function markAttemptsExhausted(int $reconciliationId): void
    {
        $database = $this->db();
        $updated = $database->query($database->prepare(
            sprintf(
                'UPDATE %s SET reconciliation_status = %%s,'
                . ' last_error_code = %%s, last_error_at = UTC_TIMESTAMP(),'
                . ' reconciled_at = UTC_TIMESTAMP(), lease_owner = NULL,'
                . ' lease_acquired_at = NULL, lease_expires_at = NULL,'
                . ' updated_at = UTC_TIMESTAMP() WHERE id = %%d'
                . ' AND attempt_count >= %%d AND ('
                . ' reconciliation_status IN (%%s, %%s) OR ('
                . ' reconciliation_status = %%s AND ('
                . ' lease_expires_at IS NULL OR lease_expires_at <= UTC_TIMESTAMP())))',
                $this->table(self::TABLE)
            ),
            PaymentReconciliation::STATUS_MANUAL_REVIEW,
            'attempts_exhausted',
            $reconciliationId,
            self::MAX_ATTEMPTS,
            PaymentReconciliation::STATUS_PENDING,
            PaymentReconciliation::STATUS_RETRYABLE,
            PaymentReconciliation::STATUS_PROCESSING
        ));

        if ($updated === false) {
            throw new PersistenceException(
                'No fue posible cerrar los intentos agotados.'
            );
        }
    }
}
