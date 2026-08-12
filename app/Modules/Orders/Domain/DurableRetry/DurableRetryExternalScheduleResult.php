<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use InvalidArgumentException;

final class DurableRetryExternalScheduleResult
{
    public const SCHEDULED = 'scheduled';
    public const ALREADY_SCHEDULED = 'already_scheduled';
    public const FOUND = 'found';
    public const NOT_FOUND = 'not_found';
    public const CANCELLED = 'cancelled';
    public const ALREADY_ABSENT = 'already_absent';
    public const UNAVAILABLE = 'unavailable';
    public const INVALID_REQUEST = 'invalid_request';
    public const EXTERNAL_ERROR = 'external_error';

    private const ALL = [
        self::SCHEDULED,
        self::ALREADY_SCHEDULED,
        self::FOUND,
        self::NOT_FOUND,
        self::CANCELLED,
        self::ALREADY_ABSENT,
        self::UNAVAILABLE,
        self::INVALID_REQUEST,
        self::EXTERNAL_ERROR,
    ];

    private const REQUIRES_ACTION_ID = [
        self::SCHEDULED,
        self::ALREADY_SCHEDULED,
        self::FOUND,
        self::CANCELLED,
    ];

    public function __construct(
        private readonly string $code,
        private readonly ?int $scheduledActionId = null
    ) {
        if (! in_array($code, self::ALL, true)) {
            throw new InvalidArgumentException('Invalid external schedule result.');
        }
        $requiresId = in_array($code, self::REQUIRES_ACTION_ID, true);
        if (($requiresId && ($scheduledActionId === null || $scheduledActionId < 1))
            || (! $requiresId && $scheduledActionId !== null)
        ) {
            throw new InvalidArgumentException('Inconsistent external schedule result.');
        }
    }

    public function code(): string
    {
        return $this->code;
    }

    public function scheduledActionId(): ?int
    {
        return $this->scheduledActionId;
    }
}
