<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationContractException;

final class DurableRetryInitialTransferResult
{
    public const TRANSFERRED = 'transferred';
    public const ALREADY_TRANSFERRED = 'already_transferred';
    public const LEGACY_IN_FLIGHT = 'legacy_in_flight';
    public const FUNCTIONALLY_INELIGIBLE = 'functionally_ineligible';
    public const DURABLE_INCONSISTENCY = 'durable_inconsistency';
    public const PERSISTENCE_ERROR = 'persistence_error';
    public const OUTCOME_UNCERTAIN = 'outcome_uncertain';

    private function __construct(
        private readonly string $state,
        private readonly string $reason,
        private readonly ?DurableRetryGenerationIdentity $generationIdentity
    ) {
        DurableRetryInitialTransferReason::assertFor($reason, $state);

        $requiresIdentity = in_array(
            $state,
            [self::TRANSFERRED, self::ALREADY_TRANSFERRED],
            true
        );
        $permitsIdentity = $requiresIdentity || in_array(
            $state,
            [self::DURABLE_INCONSISTENCY, self::OUTCOME_UNCERTAIN],
            true
        );
        if (($requiresIdentity && $generationIdentity === null)
            || (! $permitsIdentity && $generationIdentity !== null)
            || ($generationIdentity !== null && ! $generationIdentity->isInitial())
        ) {
            throw DurableRetryActivationContractException::forCode(
                DurableRetryActivationContractException::INVALID_INITIAL_TRANSFER_RESULT
            );
        }
    }

    public static function transferred(
        DurableRetryGenerationIdentity $identity
    ): self {
        return new self(
            self::TRANSFERRED,
            DurableRetryInitialTransferReason::INITIAL_TRANSFER_CREATED,
            $identity
        );
    }

    public static function alreadyTransferred(
        DurableRetryGenerationIdentity $identity
    ): self {
        return new self(
            self::ALREADY_TRANSFERRED,
            DurableRetryInitialTransferReason::EQUIVALENT_TRANSFER_EXISTS,
            $identity
        );
    }

    public static function legacyInFlight(): self
    {
        return new self(
            self::LEGACY_IN_FLIGHT,
            DurableRetryInitialTransferReason::LEGACY_CLAIM_IN_FLIGHT,
            null
        );
    }

    public static function functionallyIneligible(string $reason): self
    {
        return new self(self::FUNCTIONALLY_INELIGIBLE, $reason, null);
    }

    public static function durableInconsistency(
        string $reason,
        ?DurableRetryGenerationIdentity $identity = null
    ): self {
        return new self(self::DURABLE_INCONSISTENCY, $reason, $identity);
    }

    public static function persistenceError(): self
    {
        return new self(
            self::PERSISTENCE_ERROR,
            DurableRetryInitialTransferReason::PERSISTENCE_WRITE_FAILED,
            null
        );
    }

    public static function outcomeUncertain(
        ?DurableRetryGenerationIdentity $identity = null
    ): self {
        return new self(
            self::OUTCOME_UNCERTAIN,
            DurableRetryInitialTransferReason::PERSISTENCE_OUTCOME_UNCERTAIN,
            $identity
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

    public function diagnosticMessage(): string
    {
        return DurableRetryInitialTransferReason::message($this->reason);
    }

    public function generationIdentity(): ?DurableRetryGenerationIdentity
    {
        return $this->generationIdentity;
    }

    public function succeeded(): bool
    {
        return in_array(
            $this->state,
            [self::TRANSFERRED, self::ALREADY_TRANSFERRED],
            true
        );
    }

    public function idempotent(): bool
    {
        return $this->state === self::ALREADY_TRANSFERRED;
    }

    public function permitsInitialExternalScheduling(): bool
    {
        return $this->state === self::TRANSFERRED;
    }

    public function requiresRecovery(): bool
    {
        return in_array(
            $this->state,
            [
                self::DURABLE_INCONSISTENCY,
                self::PERSISTENCE_ERROR,
                self::OUTCOME_UNCERTAIN,
            ],
            true
        );
    }

    public function blocksLegacy(): bool
    {
        return $this->state !== self::LEGACY_IN_FLIGHT;
    }
}
