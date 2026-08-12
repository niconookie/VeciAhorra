<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationContractException;

final class DurableRetryInitialTransferReason
{
    public const INITIAL_TRANSFER_CREATED = 'initial_transfer_created';
    public const EQUIVALENT_TRANSFER_EXISTS = 'equivalent_transfer_exists';
    public const LEGACY_CLAIM_IN_FLIGHT = 'legacy_claim_in_flight';
    public const FUNCTIONAL_RECORD_ABSENT = 'functional_record_absent';
    public const FUNCTIONAL_STATE_INELIGIBLE = 'functional_state_ineligible';
    public const EXISTING_TRANSFER_INCOMPATIBLE = 'existing_transfer_incompatible';
    public const DUPLICATE_DURABLE_IDENTITY = 'duplicate_durable_identity';
    public const PERSISTENCE_WRITE_FAILED = 'persistence_write_failed';
    public const PERSISTENCE_OUTCOME_UNCERTAIN = 'persistence_outcome_uncertain';

    private const BY_STATE = [
        DurableRetryInitialTransferResult::TRANSFERRED => [
            self::INITIAL_TRANSFER_CREATED,
        ],
        DurableRetryInitialTransferResult::ALREADY_TRANSFERRED => [
            self::EQUIVALENT_TRANSFER_EXISTS,
        ],
        DurableRetryInitialTransferResult::LEGACY_IN_FLIGHT => [
            self::LEGACY_CLAIM_IN_FLIGHT,
        ],
        DurableRetryInitialTransferResult::FUNCTIONALLY_INELIGIBLE => [
            self::FUNCTIONAL_RECORD_ABSENT,
            self::FUNCTIONAL_STATE_INELIGIBLE,
        ],
        DurableRetryInitialTransferResult::DURABLE_INCONSISTENCY => [
            self::EXISTING_TRANSFER_INCOMPATIBLE,
            self::DUPLICATE_DURABLE_IDENTITY,
        ],
        DurableRetryInitialTransferResult::PERSISTENCE_ERROR => [
            self::PERSISTENCE_WRITE_FAILED,
        ],
        DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN => [
            self::PERSISTENCE_OUTCOME_UNCERTAIN,
        ],
    ];

    private const MESSAGES = [
        self::INITIAL_TRANSFER_CREATED => 'Initial durable retry authority transfer created.',
        self::EQUIVALENT_TRANSFER_EXISTS => 'Equivalent durable retry authority transfer already exists.',
        self::LEGACY_CLAIM_IN_FLIGHT => 'Legacy scheduling authority is already in flight.',
        self::FUNCTIONAL_RECORD_ABSENT => 'Functional authority record is absent.',
        self::FUNCTIONAL_STATE_INELIGIBLE => 'Functional authority state is ineligible.',
        self::EXISTING_TRANSFER_INCOMPATIBLE => 'Existing durable retry authority transfer is incompatible.',
        self::DUPLICATE_DURABLE_IDENTITY => 'Duplicate durable retry generation identity detected.',
        self::PERSISTENCE_WRITE_FAILED => 'Durable retry authority transfer persistence failed.',
        self::PERSISTENCE_OUTCOME_UNCERTAIN => 'Durable retry authority transfer outcome is uncertain.',
    ];

    public static function all(): array
    {
        return array_keys(self::MESSAGES);
    }

    public static function allowedFor(string $state): array
    {
        if (! isset(self::BY_STATE[$state])) {
            throw DurableRetryActivationContractException::forCode(
                DurableRetryActivationContractException::INVALID_INITIAL_TRANSFER_RESULT
            );
        }

        return self::BY_STATE[$state];
    }

    public static function assertFor(string $reason, string $state): void
    {
        if (! in_array($reason, self::allowedFor($state), true)) {
            throw DurableRetryActivationContractException::forCode(
                DurableRetryActivationContractException::INVALID_INITIAL_TRANSFER_RESULT
            );
        }
    }

    public static function message(string $reason): string
    {
        if (! isset(self::MESSAGES[$reason])) {
            throw DurableRetryActivationContractException::forCode(
                DurableRetryActivationContractException::INVALID_INITIAL_TRANSFER_RESULT
            );
        }

        return self::MESSAGES[$reason];
    }

    private function __construct()
    {
    }
}
