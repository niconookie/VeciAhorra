<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use InvalidArgumentException;

final class DurableRetryInitialProductionRoutingResult
{
    public const LEGACY_SCHEDULED = 'legacy_scheduled';
    public const LEGACY_UNAVAILABLE = 'legacy_unavailable';
    public const DURABLE_SYNCHRONIZED = 'durable_synchronized';
    public const DURABLE_ALREADY_SYNCHRONIZED = 'durable_already_synchronized';
    public const DURABLE_EXTERNAL_UNAVAILABLE = 'durable_external_unavailable';
    public const DURABLE_COORDINATION_FAILED = 'durable_coordination_failed';
    public const DURABLE_COORDINATION_UNCERTAIN = 'durable_coordination_uncertain';
    public const AUTHORITY_CLOSED = 'authority_closed';
    public const RESOLUTION_FAILED = 'resolution_failed';
    public const INVALID_INPUT = 'invalid_input';
    public const DEPENDENCY_FAILURE = 'dependency_failure';

    private const ALL = [
        self::LEGACY_SCHEDULED,
        self::LEGACY_UNAVAILABLE,
        self::DURABLE_SYNCHRONIZED,
        self::DURABLE_ALREADY_SYNCHRONIZED,
        self::DURABLE_EXTERNAL_UNAVAILABLE,
        self::DURABLE_COORDINATION_FAILED,
        self::DURABLE_COORDINATION_UNCERTAIN,
        self::AUTHORITY_CLOSED,
        self::RESOLUTION_FAILED,
        self::INVALID_INPUT,
        self::DEPENDENCY_FAILURE,
    ];

    private function __construct(
        private readonly string $state,
        private readonly string $reason,
        private readonly int $reconciliationId,
        private readonly ?int $scheduleId,
        private readonly ?int $generation,
        private readonly ?int $scheduledActionId,
        private readonly bool $legacyScheduled,
        private readonly bool $requiresIntervention
    ) {
        $durable = str_starts_with($state, 'durable_');
        $success = in_array($state, [
            self::DURABLE_SYNCHRONIZED,
            self::DURABLE_ALREADY_SYNCHRONIZED,
        ], true);
        if (! in_array($state, self::ALL, true)
            || $reason === ''
            || (($state === self::INVALID_INPUT) !== ($reconciliationId === 0))
            || ($state !== self::INVALID_INPUT && $reconciliationId < 1)
            || ($scheduleId !== null && $scheduleId < 1)
            || ($generation !== null && $generation < 1)
            || ($scheduledActionId !== null && $scheduledActionId < 1)
            || ($legacyScheduled !== ($state === self::LEGACY_SCHEDULED))
            || ($durable !== ($scheduleId !== null && $generation !== null))
            || ($success && $scheduledActionId === null)
            || ($state === self::DURABLE_COORDINATION_UNCERTAIN
                && ! $requiresIntervention)
            || (! $durable && $scheduledActionId !== null)
        ) {
            throw new InvalidArgumentException('Invalid initial production routing result.');
        }
    }

    public static function legacyScheduled(int $id, string $reason): self
    {
        return new self(self::LEGACY_SCHEDULED, $reason, $id, null, null, null, true, false);
    }

    public static function legacyUnavailable(int $id, string $reason): self
    {
        return new self(self::LEGACY_UNAVAILABLE, $reason, $id, null, null, null, false, false);
    }

    public static function fromScheduling(
        int $id,
        DurableRetryInitialSchedulingResult $scheduling
    ): self {
        $state = match ($scheduling->state()) {
            DurableRetryInitialSchedulingResult::SYNCHRONIZED => self::DURABLE_SYNCHRONIZED,
            DurableRetryInitialSchedulingResult::ALREADY_SYNCHRONIZED => self::DURABLE_ALREADY_SYNCHRONIZED,
            DurableRetryInitialSchedulingResult::EXTERNAL_UNAVAILABLE => self::DURABLE_EXTERNAL_UNAVAILABLE,
            DurableRetryInitialSchedulingResult::COORDINATION_FAILED => self::DURABLE_COORDINATION_FAILED,
            DurableRetryInitialSchedulingResult::COORDINATION_UNCERTAIN => self::DURABLE_COORDINATION_UNCERTAIN,
        };

        return new self(
            $state,
            $scheduling->reason(),
            $id,
            $scheduling->scheduleId(),
            $scheduling->generation(),
            $scheduling->scheduledActionId(),
            false,
            $scheduling->requiresIntervention()
        );
    }

    public static function authorityClosed(
        int $id,
        DurableRetryInitialAuthorityProductionResult $authority
    ): self {
        if ($authority->permitsLegacyProduction()
            || $authority->durableAuthorityConfirmed()
        ) {
            throw new InvalidArgumentException('Authority result does not close routing.');
        }

        return new self(
            self::AUTHORITY_CLOSED,
            $authority->reason(),
            $id,
            null,
            null,
            null,
            false,
            $authority->requiresRecovery()
        );
    }

    public static function resolutionFailed(
        int $id,
        DurableRetryInitialScheduleResolutionResult $resolution
    ): self {
        if ($resolution->mayContinueToA7()) {
            throw new InvalidArgumentException('Resolution result does not close routing.');
        }

        return new self(
            self::RESOLUTION_FAILED,
            $resolution->reason(),
            $id,
            null,
            null,
            null,
            false,
            true
        );
    }

    public static function invalidInput(): self
    {
        return new self(self::INVALID_INPUT, self::INVALID_INPUT, 0, null, null, null, false, false);
    }

    public static function dependencyFailure(int $id): self
    {
        return new self(
            self::DEPENDENCY_FAILURE,
            DurableRetryInitialAuthorityProductionResult::DEPENDENCY_FAILURE,
            $id,
            null,
            null,
            null,
            false,
            true
        );
    }

    public function state(): string { return $this->state; }
    public function reason(): string { return $this->reason; }
    public function reconciliationId(): int { return $this->reconciliationId; }
    public function scheduleId(): ?int { return $this->scheduleId; }
    public function generation(): ?int { return $this->generation; }
    public function scheduledActionId(): ?int { return $this->scheduledActionId; }
    public function legacyScheduledFlag(): bool { return $this->legacyScheduled; }
    public function requiresIntervention(): bool { return $this->requiresIntervention; }
    public function permitsLegacy(): bool
    {
        return in_array($this->state, [self::LEGACY_SCHEDULED, self::LEGACY_UNAVAILABLE], true);
    }
}
