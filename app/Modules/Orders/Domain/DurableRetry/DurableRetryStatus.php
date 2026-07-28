<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use InvalidArgumentException;

final class DurableRetryStatus
{
    public const DISPATCHING = 'dispatching';
    public const SCHEDULED = 'scheduled';
    public const CLAIMED = 'claimed';
    public const CONSUMED = 'consumed';
    public const SUPERSEDED = 'superseded';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';
    public const ORPHANED = 'orphaned';

    private const ALL = [
        self::DISPATCHING,
        self::SCHEDULED,
        self::CLAIMED,
        self::CONSUMED,
        self::SUPERSEDED,
        self::CANCELLED,
        self::FAILED,
        self::ORPHANED,
    ];

    private const ACTIVE = [
        self::DISPATCHING,
        self::SCHEDULED,
        self::CLAIMED,
    ];

    private const TRANSITIONS = [
        self::DISPATCHING => [
            self::SCHEDULED,
            self::SUPERSEDED,
            self::CANCELLED,
            self::FAILED,
            self::ORPHANED,
        ],
        self::SCHEDULED => [
            self::CLAIMED,
            self::SUPERSEDED,
            self::CANCELLED,
            self::FAILED,
            self::ORPHANED,
        ],
        self::CLAIMED => [
            self::CONSUMED,
            self::SUPERSEDED,
            self::FAILED,
            self::ORPHANED,
        ],
        self::CONSUMED => [],
        self::SUPERSEDED => [],
        self::CANCELLED => [],
        self::FAILED => [],
        self::ORPHANED => [],
    ];

    public static function all(): array
    {
        return self::ALL;
    }

    public static function active(): array
    {
        return self::ACTIVE;
    }

    public static function assert(string $status): void
    {
        if (! in_array($status, self::ALL, true)) {
            throw new InvalidArgumentException('Invalid durable retry status.');
        }
    }

    public static function isActive(string $status): bool
    {
        self::assert($status);

        return in_array($status, self::ACTIVE, true);
    }

    public static function assertActiveSlot(string $status, mixed $slot): void
    {
        $expected = self::isActive($status) ? 1 : null;

        if ($slot !== $expected) {
            throw new InvalidArgumentException('Invalid active slot for status.');
        }
    }

    public static function canTransition(string $from, string $to): bool
    {
        self::assert($from);
        self::assert($to);

        return in_array($to, self::TRANSITIONS[$from], true);
    }
}
