<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use InvalidArgumentException;

final class DurableRetryReason
{
    public const RETRYABLE_FAILURE = 'retryable_failure';
    public const STAGE_BECAME_TERMINAL = 'stage_became_terminal';
    public const RETRY_CONSUMED = 'retry_consumed';
    public const SUPERSEDED_GENERATION = 'superseded_generation';
    public const CANCELLED_BY_AUTHORITY = 'cancelled_by_authority';
    public const SCHEDULING_FAILED = 'scheduling_failed';
    public const DISPATCH_RECOVERY_EXHAUSTED = 'dispatch_recovery_exhausted';
    public const CALLBACK_REJECTED = 'callback_rejected';
    public const EXTERNAL_ACTION_MISSING = 'external_action_missing';
    public const EXTERNAL_ACTION_MISMATCH = 'external_action_mismatch';
    public const INCONSISTENCY_REQUIRES_REMEDIATION = 'inconsistency_requires_remediation';

    private const BY_STATUS = [
        DurableRetryStatus::DISPATCHING => [self::RETRYABLE_FAILURE],
        DurableRetryStatus::SCHEDULED => [self::RETRYABLE_FAILURE],
        DurableRetryStatus::CLAIMED => [self::RETRYABLE_FAILURE],
        DurableRetryStatus::CONSUMED => [
            self::STAGE_BECAME_TERMINAL,
            self::RETRY_CONSUMED,
        ],
        DurableRetryStatus::SUPERSEDED => [self::SUPERSEDED_GENERATION],
        DurableRetryStatus::CANCELLED => [self::CANCELLED_BY_AUTHORITY],
        DurableRetryStatus::FAILED => [
            self::SCHEDULING_FAILED,
            self::DISPATCH_RECOVERY_EXHAUSTED,
            self::CALLBACK_REJECTED,
        ],
        DurableRetryStatus::ORPHANED => [
            self::EXTERNAL_ACTION_MISSING,
            self::EXTERNAL_ACTION_MISMATCH,
            self::INCONSISTENCY_REQUIRES_REMEDIATION,
        ],
    ];

    public static function all(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::BY_STATUS))));
    }

    public static function assertForStatus(string $reason, string $status): void
    {
        DurableRetryStatus::assert($status);

        if (! in_array($reason, self::BY_STATUS[$status], true)) {
            throw new InvalidArgumentException('Invalid durable retry reason for status.');
        }
    }
}
