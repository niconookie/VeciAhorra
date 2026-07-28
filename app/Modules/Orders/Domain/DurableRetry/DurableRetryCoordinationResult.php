<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use InvalidArgumentException;

final class DurableRetryCoordinationResult
{
    public const SYNCHRONIZED_NEW = 'synchronized_new';
    public const SYNCHRONIZED_EXISTING = 'synchronized_existing';
    public const ALREADY_SYNCHRONIZED = 'already_synchronized';
    public const NOT_FOUND = 'not_found';
    public const STALE_GENERATION = 'stale_generation';
    public const INELIGIBLE_STATE = 'ineligible_state';
    public const DURABLE_INCONSISTENCY = 'durable_inconsistency';
    public const EXTERNAL_INCONSISTENCY = 'external_inconsistency';
    public const CONCURRENT_CONVERGENCE = 'concurrent_convergence';
    public const CONFLICT_COMPENSATED = 'conflict_compensated';
    public const COMPENSATION_UNCONFIRMED = 'compensation_unconfirmed';
    public const EXTERNAL_UNAVAILABLE = 'external_unavailable';
    public const EXTERNAL_ERROR = 'external_error';
    public const PERSISTENCE_ERROR = 'persistence_error';

    private const SUCCESS = [
        self::SYNCHRONIZED_NEW,
        self::SYNCHRONIZED_EXISTING,
        self::ALREADY_SYNCHRONIZED,
        self::CONCURRENT_CONVERGENCE,
    ];

    private const ALL = [
        ...self::SUCCESS,
        self::NOT_FOUND,
        self::STALE_GENERATION,
        self::INELIGIBLE_STATE,
        self::DURABLE_INCONSISTENCY,
        self::EXTERNAL_INCONSISTENCY,
        self::CONFLICT_COMPENSATED,
        self::COMPENSATION_UNCONFIRMED,
        self::EXTERNAL_UNAVAILABLE,
        self::EXTERNAL_ERROR,
        self::PERSISTENCE_ERROR,
    ];

    public function __construct(
        private readonly string $code,
        private readonly int $scheduleId,
        private readonly int $generation,
        private readonly ?int $scheduledActionId = null,
        private readonly bool $compensated = false,
        private readonly bool $interventionRequired = false
    ) {
        if (! in_array($code, self::ALL, true)
            || $scheduleId < 1
            || $generation < 1
            || ($scheduledActionId !== null && $scheduledActionId < 1)
            || ($compensated && $scheduledActionId === null)
            || ($code === self::COMPENSATION_UNCONFIRMED && ! $interventionRequired)
        ) {
            throw new InvalidArgumentException('Invalid durable retry coordination result.');
        }
    }

    public function code(): string
    {
        return $this->code;
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

    public function succeeded(): bool
    {
        return in_array($this->code, self::SUCCESS, true);
    }

    public function compensated(): bool
    {
        return $this->compensated;
    }

    public function interventionRequired(): bool
    {
        return $this->interventionRequired;
    }
}
