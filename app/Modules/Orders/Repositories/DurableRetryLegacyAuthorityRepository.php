<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Repositories;

use Throwable;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacyExclusionInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentityCollection;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryIndeterminateReason;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityBatchResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityEntry;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use wpdb;

final class DurableRetryLegacyAuthorityRepository implements DurableRetryLegacyExclusionInterface
{
    private const TABLE = 'durable_retry_schedules';

    private const INTEGER_COLUMNS = [
        'id',
        'subject_id',
        'generation',
        'attempt_number',
        'version',
    ];

    private const NULLABLE_INTEGER_COLUMNS = [
        'completion_id',
        'scheduled_action_id',
        'active_slot',
    ];

    private readonly string $table;

    public function __construct(private readonly wpdb $database)
    {
        $this->table = $database->prefix
            . Config::TABLE_PREFIX
            . self::TABLE;
    }

    public function classify(
        DurableRetryAuthorityIdentity $identity
    ): DurableRetryLegacyAuthorityResult {
        $collection = DurableRetryAuthorityIdentityCollection::fromIdentities(
            $identity
        );

        return $this->classifyBatch($collection)->forIdentity($identity);
    }

    public function classifyBatch(
        DurableRetryAuthorityIdentityCollection $identities
    ): DurableRetryLegacyAuthorityBatchResult {
        if ($identities->isEmpty()) {
            return DurableRetryLegacyAuthorityBatchResult::fromEntries(
                $identities
            );
        }

        $requested = iterator_to_array($identities);
        $subjectIds = array_map(
            static fn (DurableRetryAuthorityIdentity $identity): int =>
                $identity->subjectId(),
            $requested
        );
        $placeholders = implode(', ', array_fill(0, count($subjectIds), '%d'));
        $sql = 'SELECT * FROM ' . $this->table
            . ' WHERE stage = %s AND subject_id IN (' . $placeholders . ')'
            . ' ORDER BY subject_id ASC, generation ASC, id ASC';

        try {
            $prepared = $this->database->prepare(
                $sql,
                $requested[0]->stage(),
                ...$subjectIds
            );
            $rows = $this->database->get_results($prepared, ARRAY_A);
        } catch (Throwable) {
            return DurableRetryLegacyAuthorityBatchResult::indeterminateAll(
                $identities,
                DurableRetryIndeterminateReason::QUERY_FAILED
            );
        }

        if ($rows === null || $this->database->last_error !== '') {
            return DurableRetryLegacyAuthorityBatchResult::indeterminateAll(
                $identities,
                DurableRetryIndeterminateReason::QUERY_FAILED
            );
        }

        $bySubject = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                return DurableRetryLegacyAuthorityBatchResult::indeterminateAll(
                    $identities,
                    DurableRetryIndeterminateReason::INCOMPLETE_RESULT
                );
            }
            $subjectId = $this->databaseInteger($row['subject_id'] ?? null);
            if ($subjectId === null || ! in_array($subjectId, $subjectIds, true)) {
                return DurableRetryLegacyAuthorityBatchResult::indeterminateAll(
                    $identities,
                    DurableRetryIndeterminateReason::CORRUPT_IDENTITY
                );
            }
            $bySubject[$subjectId][] = $row;
        }

        $entries = [];
        foreach ($requested as $identity) {
            $entries[] = new DurableRetryLegacyAuthorityEntry(
                $identity,
                $this->classifyRows(
                    $identity,
                    $bySubject[$identity->subjectId()] ?? []
                )
            );
        }

        return DurableRetryLegacyAuthorityBatchResult::fromEntries(
            $identities,
            ...$entries
        );
    }

    private function classifyRows(
        DurableRetryAuthorityIdentity $identity,
        array $rows
    ): DurableRetryLegacyAuthorityResult {
        $initial = [];
        foreach ($rows as $row) {
            try {
                $normalized = $this->normalize($row);
                $snapshot = DurableRetryScheduleSnapshot::fromArray($normalized);
            } catch (Throwable) {
                return DurableRetryLegacyAuthorityResult::indeterminate(
                    DurableRetryIndeterminateReason::INCOMPLETE_RESULT
                );
            }
            if ($snapshot->stage() !== $identity->stage()
                || $snapshot->subjectId() !== $identity->subjectId()
            ) {
                return DurableRetryLegacyAuthorityResult::indeterminate(
                    DurableRetryIndeterminateReason::CORRUPT_IDENTITY
                );
            }
            if ($snapshot->generation() === 1) {
                $initial[] = $snapshot->toArray();
            }
        }

        if ($initial === []) {
            return DurableRetryLegacyAuthorityResult::legacy();
        }

        $first = $initial[0];
        foreach ($initial as $candidate) {
            if ($candidate !== $first) {
                return DurableRetryLegacyAuthorityResult::indeterminate(
                    DurableRetryIndeterminateReason::PERSISTED_DUPLICATE
                );
            }
        }

        return DurableRetryLegacyAuthorityResult::durable();
    }

    private function normalize(array $row): array
    {
        foreach (self::INTEGER_COLUMNS as $column) {
            $value = $this->databaseInteger($row[$column] ?? null);
            if ($value === null) {
                throw new \InvalidArgumentException('Invalid durable retry row.');
            }
            $row[$column] = $value;
        }
        foreach (self::NULLABLE_INTEGER_COLUMNS as $column) {
            if (($row[$column] ?? null) !== null) {
                $value = $this->databaseInteger($row[$column]);
                if ($value === null) {
                    throw new \InvalidArgumentException('Invalid durable retry row.');
                }
                $row[$column] = $value;
            } else {
                $row[$column] = null;
            }
        }

        return $row;
    }

    private function databaseInteger(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }
        $canonical = (string) $value;
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $canonical) !== 1
            || strlen($canonical) > strlen((string) PHP_INT_MAX)
            || (
                strlen($canonical) === strlen((string) PHP_INT_MAX)
                && strcmp($canonical, (string) PHP_INT_MAX) > 0
            )
        ) {
            return null;
        }

        return (int) $canonical;
    }
}
