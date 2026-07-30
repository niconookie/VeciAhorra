<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationContractException;

final class DurableRetryInitialAuthorityProductionResult
{
    public const LEGACY_ALLOWED = 'legacy_allowed';
    public const LEGACY_IN_FLIGHT = 'legacy_in_flight';
    public const DURABLE_EXISTING = 'durable_existing';
    public const DURABLE_CREATED = 'durable_created';
    public const DURABLE_CONVERGED = 'durable_converged';
    public const FUNCTIONALLY_INELIGIBLE = 'functionally_ineligible';
    public const AUTHORITY_INDETERMINATE = 'authority_indeterminate';
    public const DURABLE_INCONSISTENCY = 'durable_inconsistency';
    public const CONFIGURATION_INVALID = 'configuration_invalid';
    public const PERSISTENCE_ERROR = 'persistence_error';
    public const OUTCOME_UNCERTAIN = 'outcome_uncertain';
    public const OPERATIONAL_FAILURE = 'operational_failure';

    public const ACTIVATION_POLICY_REJECTED = 'activation_policy_rejected';
    public const DURABLE_AUTHORITY_ALREADY_EXISTS =
        'durable_authority_already_exists';
    public const DEPENDENCY_FAILURE = 'dependency_failure';

    private const DURABLE_STATES = [
        self::DURABLE_EXISTING,
        self::DURABLE_CREATED,
        self::DURABLE_CONVERGED,
    ];

    private const RECOVERY_STATES = [
        self::LEGACY_IN_FLIGHT,
        self::AUTHORITY_INDETERMINATE,
        self::DURABLE_INCONSISTENCY,
        self::PERSISTENCE_ERROR,
        self::OUTCOME_UNCERTAIN,
        self::OPERATIONAL_FAILURE,
    ];

    private function __construct(
        private readonly string $state,
        private readonly string $reason,
        private readonly ?DurableRetryLegacyAuthorityResult $authorityResult,
        private readonly ?DurableRetryInitialTransferResult $transferResult
    ) {
        $this->assertValid();
    }

    public static function legacyAllowed(
        DurableRetryLegacyAuthorityResult $authority
    ): self {
        return new self(
            self::LEGACY_ALLOWED,
            self::ACTIVATION_POLICY_REJECTED,
            $authority,
            null
        );
    }

    public static function durableExisting(
        DurableRetryLegacyAuthorityResult $authority
    ): self {
        return new self(
            self::DURABLE_EXISTING,
            self::DURABLE_AUTHORITY_ALREADY_EXISTS,
            $authority,
            null
        );
    }

    public static function authorityIndeterminate(
        DurableRetryLegacyAuthorityResult $authority
    ): self {
        return new self(
            self::AUTHORITY_INDETERMINATE,
            (string) $authority->reason(),
            $authority,
            null
        );
    }

    public static function configurationInvalid(
        DurableRetryLegacyAuthorityResult $authority,
        string $reason
    ): self {
        return new self(
            self::CONFIGURATION_INVALID,
            $reason,
            $authority,
            null
        );
    }

    public static function fromTransfer(
        DurableRetryLegacyAuthorityResult $authority,
        DurableRetryInitialTransferResult $transfer
    ): self {
        $state = match ($transfer->state()) {
            DurableRetryInitialTransferResult::TRANSFERRED =>
                self::DURABLE_CREATED,
            DurableRetryInitialTransferResult::ALREADY_TRANSFERRED =>
                self::DURABLE_CONVERGED,
            DurableRetryInitialTransferResult::LEGACY_IN_FLIGHT =>
                self::LEGACY_IN_FLIGHT,
            DurableRetryInitialTransferResult::FUNCTIONALLY_INELIGIBLE =>
                self::FUNCTIONALLY_INELIGIBLE,
            DurableRetryInitialTransferResult::DURABLE_INCONSISTENCY =>
                self::DURABLE_INCONSISTENCY,
            DurableRetryInitialTransferResult::PERSISTENCE_ERROR =>
                self::PERSISTENCE_ERROR,
            DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN =>
                self::OUTCOME_UNCERTAIN,
        };

        return new self($state, $transfer->reason(), $authority, $transfer);
    }

    public static function operationalFailure(
        ?DurableRetryLegacyAuthorityResult $authority,
        string $reason
    ): self {
        return new self(
            self::OPERATIONAL_FAILURE,
            $reason,
            $authority,
            null
        );
    }

    public function state(): string
    {
        return $this->state;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function authorityResult(): ?DurableRetryLegacyAuthorityResult
    {
        return $this->authorityResult;
    }

    public function transferResult(): ?DurableRetryInitialTransferResult
    {
        return $this->transferResult;
    }

    public function permitsLegacyProduction(): bool
    {
        return $this->state === self::LEGACY_ALLOWED;
    }

    public function durableAuthorityConfirmed(): bool
    {
        return in_array($this->state, self::DURABLE_STATES, true);
    }

    public function requiresRecovery(): bool
    {
        return in_array($this->state, self::RECOVERY_STATES, true);
    }

    private function assertValid(): void
    {
        if ($this->state === self::OPERATIONAL_FAILURE) {
            if (! in_array(
                $this->reason,
                [
                    self::DEPENDENCY_FAILURE,
                    'activation_configuration_source_unavailable',
                ],
                true
            ) || $this->transferResult !== null) {
                $this->invalid();
            }

            return;
        }

        if ($this->authorityResult === null) {
            $this->invalid();
        }
        if ($this->state === self::LEGACY_ALLOWED) {
            if (! $this->authorityResult->isLegacyAuthorized()
                || $this->reason !== self::ACTIVATION_POLICY_REJECTED
                || $this->transferResult !== null
            ) {
                $this->invalid();
            }

            return;
        }
        if ($this->state === self::DURABLE_EXISTING) {
            if (! $this->authorityResult->isDurable()
                || $this->reason !== self::DURABLE_AUTHORITY_ALREADY_EXISTS
                || $this->transferResult !== null
            ) {
                $this->invalid();
            }

            return;
        }
        if ($this->state === self::AUTHORITY_INDETERMINATE) {
            if (! $this->authorityResult->isIndeterminate()
                || $this->reason !== $this->authorityResult->reason()
                || $this->transferResult !== null
            ) {
                $this->invalid();
            }

            return;
        }
        if ($this->state === self::CONFIGURATION_INVALID) {
            if (! $this->authorityResult->isLegacyAuthorized()
                || ! in_array(
                    $this->reason,
                    [
                        'invalid_activation_configuration_value',
                        'invalid_percentage',
                        'unsupported_algorithm_version',
                        'invalid_configuration_snapshot',
                    ],
                    true
                )
                || $this->transferResult !== null
            ) {
                $this->invalid();
            }

            return;
        }
        if (! $this->authorityResult->isLegacyAuthorized()
            || $this->transferResult === null
            || $this->reason !== $this->transferResult->reason()
        ) {
            $this->invalid();
        }
        $expected = match ($this->transferResult->state()) {
            DurableRetryInitialTransferResult::TRANSFERRED =>
                self::DURABLE_CREATED,
            DurableRetryInitialTransferResult::ALREADY_TRANSFERRED =>
                self::DURABLE_CONVERGED,
            DurableRetryInitialTransferResult::LEGACY_IN_FLIGHT =>
                self::LEGACY_IN_FLIGHT,
            DurableRetryInitialTransferResult::FUNCTIONALLY_INELIGIBLE =>
                self::FUNCTIONALLY_INELIGIBLE,
            DurableRetryInitialTransferResult::DURABLE_INCONSISTENCY =>
                self::DURABLE_INCONSISTENCY,
            DurableRetryInitialTransferResult::PERSISTENCE_ERROR =>
                self::PERSISTENCE_ERROR,
            DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN =>
                self::OUTCOME_UNCERTAIN,
        };
        if ($this->state !== $expected) {
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw DurableRetryActivationContractException::forCode(
            DurableRetryActivationContractException::CONTRACT_VIOLATION
        );
    }
}
