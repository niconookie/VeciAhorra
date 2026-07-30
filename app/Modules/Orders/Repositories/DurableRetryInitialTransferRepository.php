<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Repositories;

use Throwable;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialTransferRepositoryInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferReason;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use wpdb;

final class DurableRetryInitialTransferRepository
    implements DurableRetryInitialTransferRepositoryInterface
{
    private const FUNCTIONAL_TABLE = 'payment_reconciliations';
    private const DURABLE_TABLE = 'durable_retry_schedules';
    private const PRE_TRANSACTION = 'PRE_TRANSACTION';
    private const TRANSACTION_STARTED = 'TRANSACTION_STARTED';
    private const READS_AND_LOCKS = 'READS_AND_LOCKS';
    private const PRE_WRITE = 'PRE_WRITE';
    private const WRITE_ATTEMPTED = 'WRITE_ATTEMPTED';
    private const COMMIT_ATTEMPTED = 'COMMIT_ATTEMPTED';
    private const COMMIT_UNCERTAIN = 'COMMIT_UNCERTAIN';
    private const CLOSE_CONFIRMED = 'CLOSE_CONFIRMED';

    private const COLUMNS = [
        'public_id',
        'stage',
        'subject_id',
        'completion_id',
        'generation',
        'attempt_number',
        'scheduled_for',
        'scheduled_action_id',
        'dispatch_token_hash',
        'status',
        'active_slot',
        'version',
        'reason_code',
        'dispatched_at',
        'claimed_at',
        'consumed_at',
        'terminal_at',
        'created_at',
        'updated_at',
    ];

    private readonly string $functionalTable;
    private readonly string $durableTable;
    private string $phase = self::PRE_TRANSACTION;
    /** @var list<string> */
    private array $phaseHistory = [self::PRE_TRANSACTION];

    public function __construct(private readonly wpdb $database)
    {
        $prefix = $database->prefix . Config::TABLE_PREFIX;
        $this->functionalTable = $prefix . self::FUNCTIONAL_TABLE;
        $this->durableTable = $prefix . self::DURABLE_TABLE;
    }

    public function transferReconciliation(
        DurableRetryInitialTransferRequest $request
    ): DurableRetryInitialTransferResult {
        $this->phase = self::PRE_TRANSACTION;
        $this->phaseHistory = [self::PRE_TRANSACTION];
        try {
            $expected = $this->deterministicSnapshot($request);
        } catch (Throwable) {
            return DurableRetryInitialTransferResult::persistenceError();
        }

        if (! $this->statement('START TRANSACTION')) {
            return DurableRetryInitialTransferResult::persistenceError();
        }
        $this->transition(self::TRANSACTION_STARTED);

        try {
            $this->transition(self::READS_AND_LOCKS);
            $functional = $this->functionalForUpdate(
                $request->authority()->subjectId()
            );
            if ($functional === false) {
                return $this->knownFailure();
            }
            if ($functional === null) {
                return $this->closeWithRollback(
                    DurableRetryInitialTransferResult::functionallyIneligible(
                    DurableRetryInitialTransferReason::FUNCTIONAL_RECORD_ABSENT
                    )
                );
            }
            if ($this->activeLegacyClaim($functional)) {
                return $this->closeWithRollback(
                    DurableRetryInitialTransferResult::legacyInFlight()
                );
            }
            if (! $this->functionallyEligible($functional)) {
                return $this->closeWithRollback(
                    DurableRetryInitialTransferResult::functionallyIneligible(
                    DurableRetryInitialTransferReason::FUNCTIONAL_STATE_INELIGIBLE
                    )
                );
            }

            $existing = $this->durableForUpdate($request);
            if ($existing === false) {
                return $this->knownFailure();
            }
            if (count($existing) > 1) {
                return $this->closeWithRollback(
                    DurableRetryInitialTransferResult::durableInconsistency(
                    DurableRetryInitialTransferReason::DUPLICATE_DURABLE_IDENTITY,
                    $request->generationIdentity()
                    )
                );
            }
            if ($existing !== []) {
                $classification = $this->classifyExisting($existing[0], $expected);
                if ($classification === null) {
                    return $this->closeWithRollback(
                        DurableRetryInitialTransferResult::durableInconsistency(
                        DurableRetryInitialTransferReason::EXISTING_TRANSFER_INCOMPATIBLE,
                        $request->generationIdentity()
                        )
                    );
                }
                $this->transition(self::COMMIT_ATTEMPTED);
                if (! $this->statement('COMMIT')) {
                    $this->transition(self::COMMIT_UNCERTAIN);

                    return $this->reconcileUncertain($request, $expected);
                }
                $this->transition(self::CLOSE_CONFIRMED);

                return DurableRetryInitialTransferResult::alreadyTransferred(
                    $request->generationIdentity()
                );
            }

            $this->transition(self::PRE_WRITE);
            $initial = $this->initialSnapshot($expected);
            $inserted = $this->insert($initial);
            if (! $inserted) {
                return $this->reconcileAfterWriteFailure($request, $initial);
            }

            $persisted = $this->durableForUpdate($request);
            if (! is_array($persisted)) {
                return $this->knownFailure();
            }
            if (count($persisted) !== 1
                || $this->classifyExisting($persisted[0], $initial) !== true
            ) {
                return $this->closeWithRollback(
                    DurableRetryInitialTransferResult::durableInconsistency(
                        count($persisted) > 1
                            ? DurableRetryInitialTransferReason::DUPLICATE_DURABLE_IDENTITY
                            : DurableRetryInitialTransferReason::EXISTING_TRANSFER_INCOMPATIBLE,
                        $request->generationIdentity()
                    )
                );
            }

            $this->transition(self::COMMIT_ATTEMPTED);
            if (! $this->statement('COMMIT')) {
                $this->transition(self::COMMIT_UNCERTAIN);

                return $this->reconcileUncertain($request, $initial);
            }
            $this->transition(self::CLOSE_CONFIRMED);

            return DurableRetryInitialTransferResult::transferred(
                $request->generationIdentity()
            );
        } catch (Throwable) {
            return $this->phase === self::COMMIT_ATTEMPTED
                || $this->phase === self::COMMIT_UNCERTAIN
                ? $this->reconcileUncertain($request, $initial ?? $expected)
                : $this->closeFailureAfterException(
                    $this->phase === self::WRITE_ATTEMPTED
                );
        }
    }

    private function deterministicSnapshot(
        DurableRetryInitialTransferRequest $request
    ): array {
        return [
            'stage' => $request->authority()->stage(),
            'subject_id' => $request->authority()->subjectId(),
            'completion_id' => $request->completionId(),
            'generation' => $request->generation(),
            'attempt_number' => $request->attemptNumber(),
            'scheduled_for' => $request->scheduledForDatabase(),
            'scheduled_action_id' => null,
            'status' => 'dispatching',
            'active_slot' => 1,
            'version' => 1,
            'reason_code' => $request->reasonCode(),
            'dispatched_at' => null,
            'claimed_at' => null,
            'consumed_at' => null,
            'terminal_at' => null,
        ];
    }

    private function initialSnapshot(array $expected): array
    {
        $createdAt = $expected['scheduled_for'];
        $fields = array_merge([
            'public_id' => bin2hex(random_bytes(32)),
        ], array_slice($expected, 0, 7, true), [
            'dispatch_token_hash' => hash('sha256', random_bytes(32)),
        ], array_slice($expected, 7, null, true), [
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        DurableRetryScheduleSnapshot::validateInitial($fields);

        return $fields;
    }

    private function functionalForUpdate(int $subjectId): array|null|false
    {
        $sql = $this->database->prepare(
            'SELECT reconciliation_status, attempt_count, lease_owner,'
                . ' lease_acquired_at, lease_expires_at, lease_version,'
                . ' CASE WHEN lease_expires_at > UTC_TIMESTAMP() THEN 1 ELSE 0 END'
                . ' AS lease_active FROM ' . $this->functionalTable
                . ' WHERE id = %d LIMIT 1 FOR UPDATE',
            $subjectId
        );
        $row = $this->database->get_row($sql, ARRAY_A);

        return $this->database->last_error === '' ? $row : false;
    }

    private function durableForUpdate(
        DurableRetryInitialTransferRequest $request
    ): array|false {
        $sql = $this->database->prepare(
            'SELECT schedule.*,'
                . ' (SELECT COUNT(*) FROM ' . $this->durableTable
                . ' AS public_match WHERE public_match.public_id = schedule.public_id)'
                . ' AS evidence_public_id_count,'
                . ' (SELECT COUNT(*) FROM ' . $this->durableTable
                . ' AS token_match WHERE token_match.dispatch_token_hash'
                . ' = schedule.dispatch_token_hash) AS evidence_token_hash_count'
                . ' FROM ' . $this->durableTable . ' AS schedule'
                . ' WHERE schedule.stage = %s AND schedule.subject_id = %d'
                . ' AND schedule.generation = %d FOR UPDATE',
            $request->authority()->stage(),
            $request->authority()->subjectId(),
            $request->generation()
        );
        $rows = $this->database->get_results($sql, ARRAY_A);

        return $this->database->last_error === '' && is_array($rows)
            ? $rows
            : false;
    }

    private function durableWithoutLock(
        wpdb $database,
        DurableRetryInitialTransferRequest $request
    ): array|false {
        $sql = $database->prepare(
            'SELECT schedule.*,'
                . ' (SELECT COUNT(*) FROM ' . $this->durableTable
                . ' AS public_match WHERE public_match.public_id = schedule.public_id)'
                . ' AS evidence_public_id_count,'
                . ' (SELECT COUNT(*) FROM ' . $this->durableTable
                . ' AS token_match WHERE token_match.dispatch_token_hash'
                . ' = schedule.dispatch_token_hash) AS evidence_token_hash_count'
                . ' FROM ' . $this->durableTable . ' AS schedule'
                . ' WHERE schedule.stage = %s AND schedule.subject_id = %d'
                . ' AND schedule.generation = %d',
            $request->authority()->stage(),
            $request->authority()->subjectId(),
            $request->generation()
        );
        $rows = $database->get_results($sql, ARRAY_A);

        return $database->last_error === '' && is_array($rows)
            ? $rows
            : false;
    }

    private function activeLegacyClaim(array $row): bool
    {
        return ($row['reconciliation_status'] ?? null)
                === PaymentReconciliation::STATUS_PROCESSING
            && (int) ($row['lease_active'] ?? 0) === 1
            && is_string($row['lease_owner'] ?? null)
            && ($row['lease_owner'] ?? '') !== '';
    }

    private function functionallyEligible(array $row): bool
    {
        return ($row['reconciliation_status'] ?? null)
                === PaymentReconciliation::STATUS_PENDING
            && (int) ($row['attempt_count'] ?? -1) === 0
            && ($row['lease_owner'] ?? null) === null
            && ($row['lease_acquired_at'] ?? null) === null
            && ($row['lease_expires_at'] ?? null) === null
            && (int) ($row['lease_version'] ?? -1) === 0;
    }

    private function insert(array $fields): bool
    {
        $tokens = [];
        $values = [];
        foreach (self::COLUMNS as $column) {
            $value = $fields[$column];
            if ($value === null) {
                $tokens[] = 'NULL';
                continue;
            }
            $tokens[] = in_array($column, [
                'subject_id',
                'completion_id',
                'generation',
                'attempt_number',
                'active_slot',
                'version',
            ], true) ? '%d' : '%s';
            $values[] = $value;
        }
        $sql = 'INSERT INTO ' . $this->durableTable . ' ('
            . implode(', ', self::COLUMNS) . ') VALUES ('
            . implode(', ', $tokens) . ')';

        $prepared = $this->database->prepare($sql, ...$values);
        $this->transition(self::WRITE_ATTEMPTED);

        return $this->database->query($prepared) === 1;
    }

    /**
     * true means exact row from this invocation, false means equivalent
     * preexisting authority, null means incompatible or corrupt.
     */
    private function classifyExisting(array $row, array $initial): ?bool
    {
        try {
            if ((int) ($row['evidence_public_id_count'] ?? 0) !== 1
                || (int) ($row['evidence_token_hash_count'] ?? 0) !== 1
            ) {
                return null;
            }
            unset(
                $row['evidence_public_id_count'],
                $row['evidence_token_hash_count']
            );
            $snapshot = DurableRetryScheduleSnapshot::fromArray(
                $this->normalize($row)
            )->toArray();
        } catch (Throwable) {
            return null;
        }

        foreach ([
            'stage',
            'subject_id',
            'completion_id',
            'generation',
            'attempt_number',
            'scheduled_for',
            'scheduled_action_id',
            'status',
            'active_slot',
            'version',
            'reason_code',
            'dispatched_at',
            'claimed_at',
            'consumed_at',
            'terminal_at',
        ] as $field) {
            if ($snapshot[$field] !== $initial[$field]) {
                return null;
            }
        }
        if (! array_key_exists('public_id', $initial)) {
            return false;
        }
        if (! hash_equals($snapshot['public_id'], $initial['public_id'])
            || ! hash_equals(
                $snapshot['dispatch_token_hash'],
                $initial['dispatch_token_hash']
            )
            || $snapshot['created_at'] !== $initial['created_at']
            || $snapshot['updated_at'] !== $initial['updated_at']
        ) {
            return false;
        }

        return true;
    }

    private function normalize(array $row): array
    {
        $expected = array_merge(['id'], self::COLUMNS);
        if (array_keys($row) !== $expected) {
            throw new \InvalidArgumentException('Invalid durable transfer evidence.');
        }
        foreach ([
            'id',
            'subject_id',
            'completion_id',
            'generation',
            'attempt_number',
            'active_slot',
            'version',
        ] as $column) {
            if ($row[$column] === null) {
                continue;
            }
            $canonical = (string) $row[$column];
            if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $canonical) !== 1
                || strlen($canonical) > strlen((string) PHP_INT_MAX)
                || (
                    strlen($canonical) === strlen((string) PHP_INT_MAX)
                    && strcmp($canonical, (string) PHP_INT_MAX) > 0
                )
            ) {
                throw new \InvalidArgumentException(
                    'Invalid durable transfer evidence.'
                );
            }
            $row[$column] = (int) $canonical;
        }

        return $row;
    }

    private function reconcileAfterWriteFailure(
        DurableRetryInitialTransferRequest $request,
        array $initial
    ): DurableRetryInitialTransferResult {
        $existing = $this->durableForUpdate($request);
        if (is_array($existing) && count($existing) > 1) {
            return $this->closeWithRollback(
                DurableRetryInitialTransferResult::durableInconsistency(
                DurableRetryInitialTransferReason::DUPLICATE_DURABLE_IDENTITY,
                $request->generationIdentity()
                )
            );
        }
        if (is_array($existing) && $existing !== []) {
            $classification = $this->classifyExisting($existing[0], $initial);
            if ($classification !== null) {
                $this->transition(self::COMMIT_ATTEMPTED);
                if (! $this->statement('COMMIT')) {
                    $this->transition(self::COMMIT_UNCERTAIN);

                    return $this->reconcileUncertain($request, $initial);
                }
                $this->transition(self::CLOSE_CONFIRMED);

                return $classification
                    ? DurableRetryInitialTransferResult::transferred(
                        $request->generationIdentity()
                    )
                    : DurableRetryInitialTransferResult::alreadyTransferred(
                        $request->generationIdentity()
                    );
            }
            return $this->closeWithRollback(
                DurableRetryInitialTransferResult::durableInconsistency(
                DurableRetryInitialTransferReason::EXISTING_TRANSFER_INCOMPATIBLE,
                $request->generationIdentity()
                )
            );
        }
        if ($existing === false) {
            return $this->uncertainAfterRollback($request, $initial);
        }

        return $this->knownFailure();
    }

    private function reconcileUncertain(
        DurableRetryInitialTransferRequest $request,
        array $initial
    ): DurableRetryInitialTransferResult {
        if ($this->phase !== self::COMMIT_UNCERTAIN) {
            $this->transition(self::COMMIT_UNCERTAIN);
        }
        $external = $this->independentConnection();
        if (! $external instanceof wpdb) {
            return DurableRetryInitialTransferResult::outcomeUncertain(
                $request->generationIdentity()
            );
        }
        try {
            $existing = $this->durableWithoutLock($external, $request);
        } finally {
            $external->close();
        }
        if (! is_array($existing) || $existing === []) {
            return DurableRetryInitialTransferResult::outcomeUncertain(
                $request->generationIdentity()
            );
        }
        if (count($existing) > 1) {
            return DurableRetryInitialTransferResult::durableInconsistency(
                DurableRetryInitialTransferReason::DUPLICATE_DURABLE_IDENTITY,
                $request->generationIdentity()
            );
        }
        $classification = $this->classifyExisting($existing[0], $initial);
        if ($classification === true) {
            return DurableRetryInitialTransferResult::transferred(
                $request->generationIdentity()
            );
        }
        if ($classification === false) {
            return DurableRetryInitialTransferResult::alreadyTransferred(
                $request->generationIdentity()
            );
        }

        return DurableRetryInitialTransferResult::durableInconsistency(
            DurableRetryInitialTransferReason::EXISTING_TRANSFER_INCOMPATIBLE,
            $request->generationIdentity()
        );
    }

    private function independentConnection(): ?wpdb
    {
        if (! defined('DB_USER') || ! defined('DB_PASSWORD')
            || ! defined('DB_NAME') || ! defined('DB_HOST')
        ) {
            return null;
        }
        try {
            $external = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            $external->prefix = $this->database->prefix;

            return $external;
        } catch (Throwable) {
            return null;
        }
    }

    private function uncertainAfterRollback(
        DurableRetryInitialTransferRequest $request,
        array $initial
    ): DurableRetryInitialTransferResult {
        return $this->rollback()
            ? DurableRetryInitialTransferResult::persistenceError()
            : DurableRetryInitialTransferResult::outcomeUncertain(
                $request->generationIdentity()
            );
    }

    private function knownFailure(): DurableRetryInitialTransferResult
    {
        return $this->rollback()
            ? DurableRetryInitialTransferResult::persistenceError()
            : DurableRetryInitialTransferResult::outcomeUncertain();
    }

    private function statement(string $sql): bool
    {
        try {
            return $this->database->query($sql) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function rollback(): bool
    {
        $closed = $this->statement('ROLLBACK');
        if ($closed) {
            $this->transition(self::CLOSE_CONFIRMED);
        }

        return $closed;
    }

    private function closeWithRollback(
        DurableRetryInitialTransferResult $result
    ): DurableRetryInitialTransferResult {
        return $this->rollback()
            ? $result
            : DurableRetryInitialTransferResult::outcomeUncertain();
    }

    private function closeFailureAfterException(
        bool $writeAttempted
    ): DurableRetryInitialTransferResult {
        if (! $this->rollback()) {
            return DurableRetryInitialTransferResult::outcomeUncertain();
        }

        return DurableRetryInitialTransferResult::persistenceError();
    }

    private function transition(string $phase): void
    {
        $this->phase = $phase;
        $this->phaseHistory[] = $phase;
    }
}
