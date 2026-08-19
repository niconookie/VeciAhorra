<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding;

use InvalidArgumentException;

final readonly class StoreOnboardingApplication
{
    public const PROVISIONING = 'provisioning';
    public const ACCOUNT_CREATED = 'account_created';
    public const PROFILE_INCOMPLETE = 'profile_incomplete';
    public const READY_TO_MATERIALIZE = 'ready_to_materialize';
    public const STORE_MATERIALIZED = 'store_materialized';
    public const PROVISIONING_FAILED = 'provisioning_failed';
    public const ABANDONED = 'abandoned';

    public const ACCOUNT_PROVISIONING_FAILED = 'account_provisioning_failed';
    public const APPLICATION_PERSISTENCE_FAILED = 'application_persistence_failed';
    public const STORE_MATERIALIZATION_FAILED = 'store_materialization_failed';
    public const TECHNICAL_OUTCOME_UNCERTAIN = 'technical_outcome_uncertain';

    private const FAILURE_CODES = [
        self::ACCOUNT_PROVISIONING_FAILED,
        self::APPLICATION_PERSISTENCE_FAILED,
        self::STORE_MATERIALIZATION_FAILED,
        self::TECHNICAL_OUTCOME_UNCERTAIN,
    ];

    private const TRANSITIONS = [
        self::PROVISIONING => [self::ACCOUNT_CREATED, self::PROVISIONING_FAILED, self::ABANDONED],
        self::ACCOUNT_CREATED => [self::PROFILE_INCOMPLETE, self::PROVISIONING_FAILED, self::ABANDONED],
        self::PROFILE_INCOMPLETE => [self::READY_TO_MATERIALIZE, self::PROVISIONING_FAILED, self::ABANDONED],
        self::READY_TO_MATERIALIZE => [self::STORE_MATERIALIZED, self::PROVISIONING_FAILED, self::ABANDONED],
        self::PROVISIONING_FAILED => [self::PROVISIONING, self::ABANDONED],
        self::STORE_MATERIALIZED => [],
        self::ABANDONED => [],
    ];

    public function __construct(public array $data)
    {
        $status = (string) ($data['status'] ?? '');
        self::assertStatus($status);
        $failureCode = $data['failure_code'] ?? null;
        if ($status === self::PROVISIONING_FAILED) {
            self::assertFailureCode(is_string($failureCode) ? $failureCode : '');
        } elseif ($failureCode !== null) {
            throw new InvalidArgumentException('onboarding_invalid_status_failure_code');
        }
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    /** @return list<string> */
    public static function failureCodes(): array
    {
        return self::FAILURE_CODES;
    }

    public static function assertFailureCode(string $failureCode): void
    {
        if (! in_array($failureCode, self::FAILURE_CODES, true)) {
            throw new InvalidArgumentException('onboarding_invalid_failure_code');
        }
    }

    public static function assertStatus(string $status): void
    {
        if (! array_key_exists($status, self::TRANSITIONS)) {
            throw new InvalidArgumentException('onboarding_invalid_status');
        }
    }

    public static function assertTransition(string $from, string $to): void
    {
        self::assertStatus($from);
        self::assertStatus($to);
        if (! in_array($to, self::TRANSITIONS[$from], true)) {
            throw new InvalidArgumentException('onboarding_invalid_transition');
        }
    }
}
