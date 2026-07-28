<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Repositories;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use mysqli;
use Throwable;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryScheduleRepositoryInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextAttemptDecision;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextGenerationPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryReason;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStatus;
use wpdb;

final class DurableRetryScheduleRepository implements DurableRetryScheduleRepositoryInterface
{
    private const TABLE = 'durable_retry_schedules';
    private const UNSIGNED_INT_MAX = 4_294_967_295;

    private const CREATE_COLUMNS = [
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

    private const IMMUTABLE_COLUMNS = [
        'id',
        'public_id',
        'stage',
        'subject_id',
        'generation',
        'attempt_number',
        'scheduled_for',
        'dispatch_token_hash',
        'created_at',
    ];

    private const WRITE_ONCE_COLUMNS = [
        'completion_id',
        'scheduled_action_id',
        'dispatched_at',
        'claimed_at',
        'consumed_at',
        'terminal_at',
    ];

    private readonly wpdb $database;
    private readonly string $table;
    private readonly Closure $duplicateKeyDetector;

    public function __construct(
        ?wpdb $database = null,
        ?callable $duplicateKeyDetector = null
    ) {
        if ($database === null) {
            global $wpdb;
            $database = $wpdb;
        }
        if (! $database instanceof wpdb) {
            throw new InvalidArgumentException('A WordPress database connection is required.');
        }

        $this->database = $database;
        $this->table = $database->prefix . Config::TABLE_PREFIX . self::TABLE;
        $this->duplicateKeyDetector = Closure::fromCallable(
            $duplicateKeyDetector ?? self::defaultDuplicateKeyDetector(...)
        );
    }

    public function create(array $initialFields): DurableRetryPersistenceResult
    {
        if (array_keys($initialFields) !== self::CREATE_COLUMNS) {
            throw new InvalidArgumentException('Invalid durable retry creation shape.');
        }
        DurableRetryScheduleSnapshot::validateInitial($initialFields);

        [$sql, $values] = $this->insertStatement($initialFields);
        $inserted = $this->database->query(
            $this->database->prepare($sql, ...$values)
        );

        if ($inserted === 1) {
            $created = $this->findById((int) $this->database->insert_id);

            return $created->snapshot() === null
                ? new DurableRetryPersistenceResult(
                    DurableRetryPersistenceResult::PERSISTENCE_ERROR
                )
                : new DurableRetryPersistenceResult(
                    DurableRetryPersistenceResult::CREATED,
                    $created->snapshot()
                );
        }

        if ($inserted !== false || ! ($this->duplicateKeyDetector)($this->database)) {
            return new DurableRetryPersistenceResult(
                DurableRetryPersistenceResult::PERSISTENCE_ERROR
            );
        }

        $existing = $this->findByIdentity(
            $initialFields['stage'],
            $initialFields['subject_id'],
            $initialFields['generation']
        );
        if ($existing->snapshot() === null) {
            return new DurableRetryPersistenceResult(
                $existing->code() === DurableRetryPersistenceResult::PERSISTENCE_ERROR
                    ? DurableRetryPersistenceResult::PERSISTENCE_ERROR
                    : DurableRetryPersistenceResult::CONFLICT
            );
        }

        return $this->creationCompatible($existing->snapshot(), $initialFields)
            ? new DurableRetryPersistenceResult(
                DurableRetryPersistenceResult::EXISTING_COMPATIBLE,
                $existing->snapshot()
            )
            : new DurableRetryPersistenceResult(
                DurableRetryPersistenceResult::CONFLICT,
                $existing->snapshot()
            );
    }

    public function findById(int $id): DurableRetryPersistenceResult
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid durable retry ID.');
        }

        return $this->readOne(
            'SELECT * FROM ' . $this->table . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    public function findByIdentity(
        string $stage,
        int $subjectId,
        int $generation
    ): DurableRetryPersistenceResult {
        DurableRetryStage::assert($stage);
        if ($subjectId < 1 || $generation < 1) {
            throw new InvalidArgumentException('Invalid durable retry identity.');
        }

        return $this->readOne(
            'SELECT * FROM ' . $this->table
                . ' WHERE stage = %s AND subject_id = %d'
                . ' AND generation = %d LIMIT 1',
            [$stage, $subjectId, $generation]
        );
    }

    public function associateScheduledAction(
        int $id,
        int $expectedVersion,
        int $scheduledActionId,
        string $dispatchedAt,
        string $updatedAt
    ): DurableRetryPersistenceResult {
        if ($expectedVersion < 1 || $scheduledActionId < 1) {
            throw new InvalidArgumentException('Invalid external association authority.');
        }

        $read = $this->findById($id);
        $current = $read->snapshot();
        if ($current === null) {
            return $read;
        }

        $fields = $current->toArray();
        if ($fields['scheduled_action_id'] !== null) {
            if ($fields['scheduled_action_id'] !== $scheduledActionId) {
                return new DurableRetryPersistenceResult(
                    DurableRetryPersistenceResult::CONFLICT,
                    $current
                );
            }
            if ($current->status() !== DurableRetryStatus::SCHEDULED) {
                return new DurableRetryPersistenceResult(
                    DurableRetryPersistenceResult::UNEXPECTED_STATE,
                    $current
                );
            }
            if ($current->version() !== $expectedVersion + 1) {
                return new DurableRetryPersistenceResult(
                    DurableRetryPersistenceResult::AUTHORITY_LOST,
                    $current
                );
            }
            if ($fields['dispatched_at'] !== $dispatchedAt
                || $fields['updated_at'] !== $updatedAt
            ) {
                return new DurableRetryPersistenceResult(
                    DurableRetryPersistenceResult::CONFLICT,
                    $current
                );
            }

            return new DurableRetryPersistenceResult(
                DurableRetryPersistenceResult::ALREADY_APPLIED,
                $current
            );
        }
        if ($current->status() !== DurableRetryStatus::DISPATCHING) {
            return new DurableRetryPersistenceResult(
                DurableRetryPersistenceResult::UNEXPECTED_STATE,
                $current
            );
        }
        if ($current->version() !== $expectedVersion) {
            return new DurableRetryPersistenceResult(
                DurableRetryPersistenceResult::AUTHORITY_LOST,
                $current
            );
        }

        $targetFields = array_replace($fields, [
            'scheduled_action_id' => $scheduledActionId,
            'status' => DurableRetryStatus::SCHEDULED,
            'version' => $expectedVersion + 1,
            'dispatched_at' => $dispatchedAt,
            'updated_at' => $updatedAt,
        ]);

        return $this->transition(
            $current,
            DurableRetryScheduleSnapshot::fromArray($targetFields)
        );
    }

    public function transition(
        DurableRetryScheduleSnapshot $expected,
        DurableRetryScheduleSnapshot $target
    ): DurableRetryPersistenceResult {
        $before = $expected->toArray();
        $after = $target->toArray();

        $this->assertTransitionContract($before, $after);
        [$setSql, $setValues] = $this->updateAssignments($after);
        $sql = 'UPDATE ' . $this->table . ' SET ' . $setSql
            . ' WHERE id = %d AND public_id = %s AND stage = %s'
            . ' AND subject_id = %d AND generation = %d'
            . ' AND status = %s AND version = %d';
        $values = array_merge($setValues, [
            $before['id'],
            $before['public_id'],
            $before['stage'],
            $before['subject_id'],
            $before['generation'],
            $before['status'],
            $before['version'],
        ]);
        foreach (self::WRITE_ONCE_COLUMNS as $column) {
            [$predicate, $predicateValues] = $this->expectedPredicate(
                $column,
                $before[$column]
            );
            $sql .= ' AND ' . $predicate;
            $values = array_merge($values, $predicateValues);
        }
        $updated = $this->database->query(
            $this->database->prepare($sql, ...$values)
        );

        if ($updated === false) {
            return new DurableRetryPersistenceResult(
                ($this->duplicateKeyDetector)($this->database)
                    ? DurableRetryPersistenceResult::CONFLICT
                    : DurableRetryPersistenceResult::PERSISTENCE_ERROR
            );
        }
        if ($updated === 1) {
            $persisted = $this->findById($target->id());

            return $persisted->snapshot() !== null
                && $persisted->snapshot()->toArray() === $after
                    ? new DurableRetryPersistenceResult(
                        DurableRetryPersistenceResult::APPLIED,
                        $persisted->snapshot()
                    )
                    : new DurableRetryPersistenceResult(
                        DurableRetryPersistenceResult::PERSISTENCE_ERROR
                    );
        }

        return $this->classifyZeroCas($before, $after);
    }

    public function supersedeAndCreateNextGeneration(
        DurableRetryScheduleSnapshot $claimed,
        DurableRetryNextAttemptDecision $decision,
        string $supersededAtUtc
    ): DurableRetryNextGenerationPersistenceResult {
        $before = $claimed->toArray();
        $invalid = $this->validateNextGenerationRequest(
            $before,
            $decision,
            $supersededAtUtc
        );
        if ($invalid !== null) {
            return new DurableRetryNextGenerationPersistenceResult($invalid);
        }

        try {
            $successorFields = $this->nextGenerationFields(
                $before,
                $decision,
                $supersededAtUtc
            );
            DurableRetryScheduleSnapshot::validateInitial($successorFields);
        } catch (Throwable) {
            return new DurableRetryNextGenerationPersistenceResult(
                DurableRetryNextGenerationPersistenceResult::PERSISTENCE_ERROR
            );
        }

        try {
            if ($this->database->query('START TRANSACTION') === false) {
                return new DurableRetryNextGenerationPersistenceResult(
                    DurableRetryNextGenerationPersistenceResult::PERSISTENCE_ERROR
                );
            }

            $updated = $this->database->query(
                $this->database->prepare(
                    'UPDATE ' . $this->table
                        . ' SET status = %s, active_slot = NULL, version = version + 1,'
                        . ' reason_code = %s, terminal_at = %s, updated_at = %s'
                        . ' WHERE id = %d AND public_id = %s AND stage = %s'
                        . ' AND subject_id = %d AND generation = %d'
                        . ' AND attempt_number = %d AND status = %s'
                        . ' AND active_slot = %d AND version = %d'
                        . ' AND scheduled_action_id = %d AND dispatch_token_hash = %s',
                    DurableRetryStatus::SUPERSEDED,
                    DurableRetryReason::SUPERSEDED_GENERATION,
                    $supersededAtUtc,
                    $supersededAtUtc,
                    $before['id'],
                    $before['public_id'],
                    $before['stage'],
                    $before['subject_id'],
                    $before['generation'],
                    $before['attempt_number'],
                    DurableRetryStatus::CLAIMED,
                    1,
                    $before['version'],
                    $before['scheduled_action_id'],
                    $before['dispatch_token_hash']
                )
            );
            if ($updated !== 1) {
                if (! $this->rollback()) {
                    return new DurableRetryNextGenerationPersistenceResult(
                        DurableRetryNextGenerationPersistenceResult::PERSISTENCE_ERROR
                    );
                }

                return $updated === 0
                    ? $this->classifyNextGenerationCasLoss(
                        $before,
                        $decision,
                        $supersededAtUtc
                    )
                    : new DurableRetryNextGenerationPersistenceResult(
                        DurableRetryNextGenerationPersistenceResult::PERSISTENCE_ERROR
                    );
            }

            [$insertSql, $insertValues] = $this->insertStatement($successorFields);
            $inserted = $this->database->query(
                $this->database->prepare($insertSql, ...$insertValues)
            );
            if ($inserted !== 1 || (int) $this->database->insert_id < 1) {
                $duplicate = ($this->duplicateKeyDetector)($this->database);
                if (! $this->rollback()) {
                    return new DurableRetryNextGenerationPersistenceResult(
                        DurableRetryNextGenerationPersistenceResult::PERSISTENCE_ERROR
                    );
                }

                return $duplicate
                    ? $this->classifyNextGenerationInsertCollision(
                        $before,
                        $decision,
                        $supersededAtUtc
                    )
                    : new DurableRetryNextGenerationPersistenceResult(
                        DurableRetryNextGenerationPersistenceResult::INSERT_FAILED
                    );
            }

            $successorId = (int) $this->database->insert_id;
            if ($successorId === $claimed->id()) {
                if (! $this->rollback()) {
                    return new DurableRetryNextGenerationPersistenceResult(
                        DurableRetryNextGenerationPersistenceResult::PERSISTENCE_ERROR
                    );
                }

                return new DurableRetryNextGenerationPersistenceResult(
                    DurableRetryNextGenerationPersistenceResult::DURABLE_INCONSISTENCY
                );
            }

            $historical = $this->findById($claimed->id())->snapshot();
            $successor = $this->findById($successorId)->snapshot();
            if (! $this->isExactSuperseded($historical, $before, $supersededAtUtc)
                || ! $this->isCompatibleSuccessor(
                    $successor,
                    $before,
                    $decision,
                    $supersededAtUtc
                )
            ) {
                if (! $this->rollback()) {
                    return new DurableRetryNextGenerationPersistenceResult(
                        DurableRetryNextGenerationPersistenceResult::PERSISTENCE_ERROR
                    );
                }

                return new DurableRetryNextGenerationPersistenceResult(
                    DurableRetryNextGenerationPersistenceResult::DURABLE_INCONSISTENCY
                );
            }

            if ($this->database->query('COMMIT') === false) {
                $this->rollback();

                return new DurableRetryNextGenerationPersistenceResult(
                    DurableRetryNextGenerationPersistenceResult::PERSISTENCE_ERROR
                );
            }

            return new DurableRetryNextGenerationPersistenceResult(
                DurableRetryNextGenerationPersistenceResult::CREATED,
                $historical,
                $successor
            );
        } catch (Throwable) {
            $this->rollback();

            return new DurableRetryNextGenerationPersistenceResult(
                DurableRetryNextGenerationPersistenceResult::PERSISTENCE_ERROR
            );
        }
    }

    private function validateNextGenerationRequest(
        array $claimed,
        DurableRetryNextAttemptDecision $decision,
        string $supersededAtUtc
    ): ?string {
        if ($claimed['status'] !== DurableRetryStatus::CLAIMED
            || $claimed['active_slot'] !== 1
            || $claimed['scheduled_action_id'] === null
            || $claimed['version'] >= self::UNSIGNED_INT_MAX
        ) {
            return DurableRetryNextGenerationPersistenceResult::INELIGIBLE_STATE;
        }
        if ($claimed['generation'] >= self::UNSIGNED_INT_MAX
            || $claimed['attempt_number'] >= self::UNSIGNED_INT_MAX
            || $decision->code() !== DurableRetryNextAttemptDecision::RETRY
            || ! $decision->createsNextGeneration()
            || $decision->nextGeneration() !== $claimed['generation'] + 1
            || $decision->nextAttemptNumber() !== $claimed['attempt_number'] + 1
        ) {
            return DurableRetryNextGenerationPersistenceResult::INVALID_DECISION;
        }

        $supersededAt = self::utc($supersededAtUtc);
        $scheduledFor = self::utc((string) $decision->scheduledForUtc());
        if ($supersededAt === null
            || $scheduledFor === null
            || $supersededAt > $scheduledFor
            || $scheduledFor->getTimestamp() - $supersededAt->getTimestamp()
                !== $decision->backoffSeconds()
        ) {
            return DurableRetryNextGenerationPersistenceResult::INVALID_DECISION;
        }

        return null;
    }

    private function nextGenerationFields(
        array $claimed,
        DurableRetryNextAttemptDecision $decision,
        string $supersededAtUtc
    ): array {
        return [
            'public_id' => hash('sha256', random_bytes(32)),
            'stage' => $claimed['stage'],
            'subject_id' => $claimed['subject_id'],
            'completion_id' => $claimed['completion_id'],
            'generation' => $decision->nextGeneration(),
            'attempt_number' => $decision->nextAttemptNumber(),
            'scheduled_for' => $decision->scheduledForUtc(),
            'scheduled_action_id' => null,
            'dispatch_token_hash' => hash('sha256', random_bytes(32)),
            'status' => DurableRetryStatus::DISPATCHING,
            'active_slot' => 1,
            'version' => 1,
            'reason_code' => DurableRetryReason::RETRYABLE_FAILURE,
            'dispatched_at' => null,
            'claimed_at' => null,
            'consumed_at' => null,
            'terminal_at' => null,
            'created_at' => $supersededAtUtc,
            'updated_at' => $supersededAtUtc,
        ];
    }

    private function classifyNextGenerationCasLoss(
        array $claimed,
        DurableRetryNextAttemptDecision $decision,
        string $supersededAtUtc
    ): DurableRetryNextGenerationPersistenceResult {
        $historical = $this->findById($claimed['id'])->snapshot();
        if ($historical === null) {
            return new DurableRetryNextGenerationPersistenceResult(
                DurableRetryNextGenerationPersistenceResult::NOT_FOUND
            );
        }
        $successor = $this->findByIdentity(
            $claimed['stage'],
            $claimed['subject_id'],
            (int) $decision->nextGeneration()
        )->snapshot();
        if ($successor !== null
            && $this->isExactSuperseded(
                $historical,
                $claimed,
                $supersededAtUtc
            )
            && $this->isCompatibleSuccessor(
                $successor,
                $claimed,
                $decision,
                $supersededAtUtc
            )
        ) {
            return new DurableRetryNextGenerationPersistenceResult(
                DurableRetryNextGenerationPersistenceResult::CONCURRENT_CONVERGENCE,
                $historical,
                $successor
            );
        }
        if ($historical->status() !== DurableRetryStatus::CLAIMED) {
            return new DurableRetryNextGenerationPersistenceResult(
                $successor === null
                    ? DurableRetryNextGenerationPersistenceResult::INELIGIBLE_STATE
                    : DurableRetryNextGenerationPersistenceResult::DURABLE_INCONSISTENCY
            );
        }

        return new DurableRetryNextGenerationPersistenceResult(
            DurableRetryNextGenerationPersistenceResult::CAS_CONFLICT
        );
    }

    private function classifyNextGenerationInsertCollision(
        array $claimed,
        DurableRetryNextAttemptDecision $decision,
        string $supersededAtUtc
    ): DurableRetryNextGenerationPersistenceResult {
        $successor = $this->findByIdentity(
            $claimed['stage'],
            $claimed['subject_id'],
            (int) $decision->nextGeneration()
        )->snapshot();
        if ($successor === null) {
            return new DurableRetryNextGenerationPersistenceResult(
                DurableRetryNextGenerationPersistenceResult::ACTIVE_SLOT_CONFLICT
            );
        }
        $historical = $this->findById($claimed['id'])->snapshot();
        if ($this->isExactSuperseded($historical, $claimed, $supersededAtUtc)
            && $this->isCompatibleSuccessor(
                $successor,
                $claimed,
                $decision,
                $supersededAtUtc
            )
        ) {
            return new DurableRetryNextGenerationPersistenceResult(
                DurableRetryNextGenerationPersistenceResult::CONCURRENT_CONVERGENCE,
                $historical,
                $successor
            );
        }

        return new DurableRetryNextGenerationPersistenceResult(
            DurableRetryNextGenerationPersistenceResult::DURABLE_INCONSISTENCY
        );
    }

    private function isExactSuperseded(
        ?DurableRetryScheduleSnapshot $snapshot,
        array $claimed,
        ?string $supersededAtUtc
    ): bool {
        if ($snapshot === null || $supersededAtUtc === null) {
            return false;
        }
        $expected = array_replace($claimed, [
            'status' => DurableRetryStatus::SUPERSEDED,
            'active_slot' => null,
            'version' => $claimed['version'] + 1,
            'reason_code' => DurableRetryReason::SUPERSEDED_GENERATION,
            'terminal_at' => $supersededAtUtc,
            'updated_at' => $supersededAtUtc,
        ]);

        return $snapshot->toArray() === $expected;
    }

    private function isCompatibleSuccessor(
        ?DurableRetryScheduleSnapshot $snapshot,
        array $claimed,
        DurableRetryNextAttemptDecision $decision,
        string $createdAtUtc
    ): bool {
        if ($snapshot === null) {
            return false;
        }
        $fields = $snapshot->toArray();

        return $snapshot->id() !== $claimed['id']
            && $fields['public_id'] !== $claimed['public_id']
            && $fields['dispatch_token_hash'] !== $claimed['dispatch_token_hash']
            && $fields['stage'] === $claimed['stage']
            && $fields['subject_id'] === $claimed['subject_id']
            && $fields['completion_id'] === $claimed['completion_id']
            && $fields['generation'] === $decision->nextGeneration()
            && $fields['attempt_number'] === $decision->nextAttemptNumber()
            && $fields['scheduled_for'] === $decision->scheduledForUtc()
            && $fields['scheduled_action_id'] === null
            && $fields['status'] === DurableRetryStatus::DISPATCHING
            && $fields['active_slot'] === 1
            && $fields['version'] === 1
            && $fields['reason_code'] === DurableRetryReason::RETRYABLE_FAILURE
            && $fields['dispatched_at'] === null
            && $fields['claimed_at'] === null
            && $fields['consumed_at'] === null
            && $fields['terminal_at'] === null
            && $fields['created_at'] === $createdAtUtc
            && $fields['updated_at'] === $createdAtUtc;
    }

    private function rollback(): bool
    {
        try {
            return $this->database->query('ROLLBACK') !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private static function utc(string $value): ?DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $utc);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false
                && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $parsed->format('Y-m-d H:i:s') !== $value
            || $parsed->getTimestamp() < 1
        ) {
            return null;
        }

        return $parsed;
    }

    private function classifyZeroCas(
        array $expected,
        array $target
    ): DurableRetryPersistenceResult {
        $read = $this->findById($expected['id']);
        $current = $read->snapshot();
        if ($current === null) {
            return $read;
        }
        $actual = $current->toArray();

        if ($actual === $target) {
            return new DurableRetryPersistenceResult(
                DurableRetryPersistenceResult::ALREADY_APPLIED,
                $current
            );
        }
        if ($actual['status'] !== $expected['status']) {
            return new DurableRetryPersistenceResult(
                DurableRetryPersistenceResult::UNEXPECTED_STATE,
                $current
            );
        }
        if ($actual['version'] !== $expected['version']) {
            return new DurableRetryPersistenceResult(
                DurableRetryPersistenceResult::AUTHORITY_LOST,
                $current
            );
        }

        return new DurableRetryPersistenceResult(
            DurableRetryPersistenceResult::CONFLICT,
            $current
        );
    }

    private function readOne(
        string $sql,
        array $values
    ): DurableRetryPersistenceResult {
        $row = $this->database->get_row(
            $this->database->prepare($sql, ...$values),
            ARRAY_A
        );
        if ($this->database->last_error !== '') {
            return new DurableRetryPersistenceResult(
                DurableRetryPersistenceResult::PERSISTENCE_ERROR
            );
        }
        if ($row === null) {
            return new DurableRetryPersistenceResult(
                DurableRetryPersistenceResult::NOT_FOUND
            );
        }

        try {
            return new DurableRetryPersistenceResult(
                DurableRetryPersistenceResult::EXISTING_COMPATIBLE,
                DurableRetryScheduleSnapshot::fromArray($this->normalizeRow($row))
            );
        } catch (InvalidArgumentException) {
            return new DurableRetryPersistenceResult(
                DurableRetryPersistenceResult::PERSISTENCE_ERROR
            );
        }
    }

    private function normalizeRow(array $row): array
    {
        $expected = array_merge(['id'], self::CREATE_COLUMNS);
        if (array_keys($row) !== $expected) {
            throw new InvalidArgumentException('Invalid persisted durable retry shape.');
        }
        foreach ([
            'id',
            'subject_id',
            'generation',
            'attempt_number',
            'version',
        ] as $name) {
            $row[$name] = self::databaseInteger($row[$name], false);
        }
        foreach (['completion_id', 'scheduled_action_id', 'active_slot'] as $name) {
            $row[$name] = self::databaseInteger($row[$name], true);
        }

        return $row;
    }

    private static function databaseInteger(mixed $value, bool $nullable): ?int
    {
        if ($nullable && $value === null) {
            return null;
        }
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException('Invalid persisted integer.');
        }
        $canonical = (string) $value;
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $canonical) !== 1
            || strlen($canonical) > strlen((string) PHP_INT_MAX)
            || (
                strlen($canonical) === strlen((string) PHP_INT_MAX)
                && strcmp($canonical, (string) PHP_INT_MAX) > 0
            )
        ) {
            throw new InvalidArgumentException('Invalid persisted integer.');
        }

        return (int) $canonical;
    }

    private function creationCompatible(
        DurableRetryScheduleSnapshot $snapshot,
        array $initial
    ): bool {
        $persisted = $snapshot->toArray();
        unset($persisted['id']);

        return $persisted === $initial;
    }

    private function assertTransitionContract(array $before, array $after): void
    {
        foreach (self::IMMUTABLE_COLUMNS as $column) {
            if ($before[$column] !== $after[$column]) {
                throw new InvalidArgumentException("Immutable field changed: {$column}.");
            }
        }
        foreach (self::WRITE_ONCE_COLUMNS as $column) {
            if ($before[$column] !== null && $before[$column] !== $after[$column]) {
                throw new InvalidArgumentException("Write-once field changed: {$column}.");
            }
        }
        if ($after['version'] !== $before['version'] + 1) {
            throw new InvalidArgumentException('CAS version must increase exactly once.');
        }
        if (! DurableRetryStatus::canTransition($before['status'], $after['status'])) {
            throw new InvalidArgumentException('Invalid durable retry transition.');
        }
    }

    private function insertStatement(array $fields): array
    {
        [$tokens, $values] = $this->tokensAndValues($fields, self::CREATE_COLUMNS);

        return [
            'INSERT INTO ' . $this->table . ' ('
                . implode(', ', self::CREATE_COLUMNS) . ') VALUES ('
                . implode(', ', $tokens) . ')',
            $values,
        ];
    }

    private function updateAssignments(array $fields): array
    {
        $columns = [
            'completion_id',
            'scheduled_action_id',
            'status',
            'active_slot',
            'version',
            'reason_code',
            'dispatched_at',
            'claimed_at',
            'consumed_at',
            'terminal_at',
            'updated_at',
        ];
        [$tokens, $values] = $this->tokensAndValues($fields, $columns);
        $assignments = [];
        foreach ($columns as $index => $column) {
            $assignments[] = $column . ' = ' . $tokens[$index];
        }

        return [implode(', ', $assignments), $values];
    }

    private function tokensAndValues(array $fields, array $columns): array
    {
        $integerColumns = [
            'subject_id',
            'completion_id',
            'generation',
            'attempt_number',
            'scheduled_action_id',
            'active_slot',
            'version',
        ];
        $tokens = [];
        $values = [];
        foreach ($columns as $column) {
            if ($fields[$column] === null) {
                $tokens[] = 'NULL';
                continue;
            }
            $tokens[] = in_array($column, $integerColumns, true) ? '%d' : '%s';
            $values[] = $fields[$column];
        }

        return [$tokens, $values];
    }

    private function expectedPredicate(string $column, mixed $value): array
    {
        if ($value === null) {
            return [$column . ' IS NULL', []];
        }
        $integer = in_array(
            $column,
            ['completion_id', 'scheduled_action_id'],
            true
        );

        return [$column . ($integer ? ' = %d' : ' = %s'), [$value]];
    }

    private static function defaultDuplicateKeyDetector(wpdb $database): bool
    {
        return $database->dbh instanceof mysqli
            && mysqli_errno($database->dbh) === 1062;
    }
}
