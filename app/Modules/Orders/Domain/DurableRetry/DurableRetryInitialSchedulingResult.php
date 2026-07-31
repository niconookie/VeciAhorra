<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use InvalidArgumentException;

final class DurableRetryInitialSchedulingResult
{
    public const SYNCHRONIZED = 'synchronized';
    public const ALREADY_SYNCHRONIZED = 'already_synchronized';
    public const EXTERNAL_UNAVAILABLE = 'external_unavailable';
    public const COORDINATION_FAILED = 'coordination_failed';
    public const COORDINATION_UNCERTAIN = 'coordination_uncertain';

    private const ALL = [
        self::SYNCHRONIZED,
        self::ALREADY_SYNCHRONIZED,
        self::EXTERNAL_UNAVAILABLE,
        self::COORDINATION_FAILED,
        self::COORDINATION_UNCERTAIN,
    ];

    private function __construct(
        private readonly string $state,
        private readonly string $reason,
        private readonly int $scheduleId,
        private readonly int $generation,
        private readonly ?int $scheduledActionId,
        private readonly bool $requiresIntervention
    ) {
        if (! in_array($state, self::ALL, true)
            || $reason === ''
            || $scheduleId < 1
            || $generation < 1
            || ($scheduledActionId !== null && $scheduledActionId < 1)
            || ($state === self::COORDINATION_UNCERTAIN) !== $requiresIntervention
            || (in_array($state, [
                self::SYNCHRONIZED,
                self::ALREADY_SYNCHRONIZED,
            ], true) && $scheduledActionId === null)
        ) {
            throw new InvalidArgumentException('Invalid initial scheduling result.');
        }
    }

    public static function synchronized(DurableRetryCoordinationResult $result): self
    {
        if (! in_array($result->code(), [
            DurableRetryCoordinationResult::SYNCHRONIZED_NEW,
            DurableRetryCoordinationResult::SYNCHRONIZED_EXISTING,
            DurableRetryCoordinationResult::CONCURRENT_CONVERGENCE,
        ], true) || ! $result->succeeded()) {
            throw new InvalidArgumentException('Invalid synchronized coordination result.');
        }

        return self::fromCoordination(self::SYNCHRONIZED, $result);
    }

    public static function alreadySynchronized(
        DurableRetryCoordinationResult $result
    ): self {
        if ($result->code() !== DurableRetryCoordinationResult::ALREADY_SYNCHRONIZED
            || ! $result->succeeded()
        ) {
            throw new InvalidArgumentException('Invalid existing coordination result.');
        }

        return self::fromCoordination(self::ALREADY_SYNCHRONIZED, $result);
    }

    public static function externalUnavailable(
        DurableRetryCoordinationResult $result
    ): self {
        if ($result->code() !== DurableRetryCoordinationResult::EXTERNAL_UNAVAILABLE
            || $result->interventionRequired()
        ) {
            throw new InvalidArgumentException('Invalid unavailable coordination result.');
        }

        return self::fromCoordination(self::EXTERNAL_UNAVAILABLE, $result);
    }

    public static function coordinationFailed(
        DurableRetryCoordinationResult $result
    ): self {
        if ($result->succeeded()
            || $result->code() === DurableRetryCoordinationResult::EXTERNAL_UNAVAILABLE
            || $result->interventionRequired()
        ) {
            throw new InvalidArgumentException('Invalid failed coordination result.');
        }

        return self::fromCoordination(self::COORDINATION_FAILED, $result);
    }

    public static function coordinationUncertain(
        DurableRetryCoordinationResult $result
    ): self {
        if ($result->succeeded() || ! $result->interventionRequired()) {
            throw new InvalidArgumentException('Invalid uncertain coordination result.');
        }

        return self::fromCoordination(self::COORDINATION_UNCERTAIN, $result);
    }

    public function state(): string
    {
        return $this->state;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function scheduleId(): int
    {
        return $this->scheduleId;
    }

    public function generation(): int
    {
        return $this->generation;
    }

    public function scheduledActionId(): ?int
    {
        return $this->scheduledActionId;
    }

    public function requiresIntervention(): bool
    {
        return $this->requiresIntervention;
    }

    public function permitsLegacy(): bool
    {
        return false;
    }

    public function mayContinueToA8(): bool
    {
        return true;
    }

    private static function fromCoordination(
        string $state,
        DurableRetryCoordinationResult $result
    ): self {
        return new self(
            $state,
            $result->code(),
            $result->scheduleId(),
            $result->generation(),
            $result->scheduledActionId(),
            $result->interventionRequired()
        );
    }
}
