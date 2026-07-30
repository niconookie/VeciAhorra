<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use DateTimeImmutable;
use InvalidArgumentException;

final class DurableRetryInitialScheduleResolutionResult
{
    public const RESOLVED_DISPATCHING = 'resolved_dispatching';
    public const RESOLVED_SCHEDULED = 'resolved_scheduled';
    public const NOT_FOUND = 'not_found';
    public const INCOMPATIBLE = 'incompatible';
    public const READ_ERROR = 'read_error';

    public const INITIAL_DISPATCH_REQUIRED = 'initial_dispatch_required';
    public const INITIAL_DISPATCH_CONFIRMED = 'initial_dispatch_confirmed';
    public const INITIAL_SCHEDULE_NOT_FOUND = 'initial_schedule_not_found';
    public const INITIAL_SCHEDULE_INCOMPATIBLE = 'initial_schedule_incompatible';
    public const INITIAL_SCHEDULE_READ_ERROR = 'initial_schedule_read_error';

    private function __construct(
        private readonly string $state,
        private readonly string $reason,
        private readonly ?int $scheduleId,
        private readonly ?int $generation,
        private readonly ?DateTimeImmutable $scheduledForUtc
    ) {
        $resolved = in_array($state, [
            self::RESOLVED_DISPATCHING,
            self::RESOLVED_SCHEDULED,
        ], true);
        $expectedReason = match ($state) {
            self::RESOLVED_DISPATCHING => self::INITIAL_DISPATCH_REQUIRED,
            self::RESOLVED_SCHEDULED => self::INITIAL_DISPATCH_CONFIRMED,
            self::NOT_FOUND => self::INITIAL_SCHEDULE_NOT_FOUND,
            self::INCOMPATIBLE => self::INITIAL_SCHEDULE_INCOMPATIBLE,
            self::READ_ERROR => self::INITIAL_SCHEDULE_READ_ERROR,
            default => null,
        };
        if ($expectedReason === null
            || $reason !== $expectedReason
            || ($resolved && ($scheduleId === null || $scheduleId < 1
                || $generation !== 1 || $scheduledForUtc === null
                || $scheduledForUtc->getOffset() !== 0
                || $scheduledForUtc->format('u') !== '000000'))
            || (! $resolved && ($scheduleId !== null || $generation !== null
                || $scheduledForUtc !== null))
        ) {
            throw new InvalidArgumentException(
                'Invalid initial schedule resolution result.'
            );
        }
    }

    public static function resolvedDispatching(
        int $scheduleId,
        int $generation,
        DateTimeImmutable $scheduledForUtc
    ): self {
        return new self(
            self::RESOLVED_DISPATCHING,
            self::INITIAL_DISPATCH_REQUIRED,
            $scheduleId,
            $generation,
            $scheduledForUtc
        );
    }

    public static function resolvedScheduled(
        int $scheduleId,
        int $generation,
        DateTimeImmutable $scheduledForUtc
    ): self {
        return new self(
            self::RESOLVED_SCHEDULED,
            self::INITIAL_DISPATCH_CONFIRMED,
            $scheduleId,
            $generation,
            $scheduledForUtc
        );
    }

    public static function notFound(): self
    {
        return new self(
            self::NOT_FOUND,
            self::INITIAL_SCHEDULE_NOT_FOUND,
            null,
            null,
            null
        );
    }

    public static function incompatible(): self
    {
        return new self(
            self::INCOMPATIBLE,
            self::INITIAL_SCHEDULE_INCOMPATIBLE,
            null,
            null,
            null
        );
    }

    public static function readError(): self
    {
        return new self(
            self::READ_ERROR,
            self::INITIAL_SCHEDULE_READ_ERROR,
            null,
            null,
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

    public function scheduleId(): ?int
    {
        return $this->scheduleId;
    }

    public function generation(): ?int
    {
        return $this->generation;
    }

    public function scheduledForUtc(): ?DateTimeImmutable
    {
        return $this->scheduledForUtc;
    }

    public function mayContinueToA7(): bool
    {
        return in_array($this->state, [
            self::RESOLVED_DISPATCHING,
            self::RESOLVED_SCHEDULED,
        ], true);
    }

    public function permitsLegacy(): bool
    {
        return false;
    }
}
