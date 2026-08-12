<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationContractException;

final class DurableRetryIndeterminateReason
{
    public const QUERY_FAILED = 'query_failed';
    public const INCOMPATIBLE_DURABLE_STATE = 'incompatible_durable_state';
    public const PERSISTED_DUPLICATE = 'persisted_duplicate';
    public const CORRUPT_IDENTITY = 'corrupt_identity';
    public const INCOMPLETE_RESULT = 'incomplete_result';
    public const UNRESOLVED_RACE = 'unresolved_race';
    public const CONSISTENCY_ERROR = 'consistency_error';

    private const MESSAGES = [
        self::QUERY_FAILED => 'Durable retry authority query failed.',
        self::INCOMPATIBLE_DURABLE_STATE => 'Durable retry authority state is incompatible.',
        self::PERSISTED_DUPLICATE => 'Duplicate durable retry authority evidence detected.',
        self::CORRUPT_IDENTITY => 'Durable retry authority identity is corrupt.',
        self::INCOMPLETE_RESULT => 'Durable retry authority evidence is incomplete.',
        self::UNRESOLVED_RACE => 'Durable retry authority race is unresolved.',
        self::CONSISTENCY_ERROR => 'Durable retry authority consistency check failed.',
    ];

    public static function all(): array
    {
        return array_keys(self::MESSAGES);
    }

    public static function assert(string $reason): void
    {
        if (! isset(self::MESSAGES[$reason])) {
            throw DurableRetryActivationContractException::forCode(
                DurableRetryActivationContractException::INVALID_AUTHORITY_RESULT
            );
        }
    }

    public static function message(string $reason): string
    {
        self::assert($reason);

        return self::MESSAGES[$reason];
    }

    private function __construct()
    {
    }
}
