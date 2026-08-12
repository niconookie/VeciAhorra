<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use InvalidArgumentException;

final class DurableRetryPersistenceResult
{
    public const CREATED = 'created';
    public const EXISTING_COMPATIBLE = 'existing_compatible';
    public const APPLIED = 'applied';
    public const ALREADY_APPLIED = 'already_applied';
    public const NOT_FOUND = 'not_found';
    public const CONFLICT = 'conflict';
    public const UNEXPECTED_STATE = 'unexpected_state';
    public const AUTHORITY_LOST = 'authority_lost';
    public const PERSISTENCE_ERROR = 'persistence_error';

    private const ALL = [
        self::CREATED,
        self::EXISTING_COMPATIBLE,
        self::APPLIED,
        self::ALREADY_APPLIED,
        self::NOT_FOUND,
        self::CONFLICT,
        self::UNEXPECTED_STATE,
        self::AUTHORITY_LOST,
        self::PERSISTENCE_ERROR,
    ];

    public function __construct(
        private readonly string $code,
        private readonly ?DurableRetryScheduleSnapshot $snapshot = null
    ) {
        if (! in_array($code, self::ALL, true)) {
            throw new InvalidArgumentException('Invalid durable retry persistence result.');
        }
    }

    public function code(): string
    {
        return $this->code;
    }

    public function snapshot(): ?DurableRetryScheduleSnapshot
    {
        return $this->snapshot;
    }

    public function succeeded(): bool
    {
        return in_array($this->code, [
            self::CREATED,
            self::EXISTING_COMPATIBLE,
            self::APPLIED,
            self::ALREADY_APPLIED,
        ], true);
    }
}
