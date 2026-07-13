<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\DTO;

use InvalidArgumentException;

final class LeaseReleaseResult
{
    public const RELEASED = 'released';
    public const ALREADY_RELEASED = 'already_released';
    public const NOT_OWNER = 'not_owner';
    public const VERSION_MISMATCH = 'version_mismatch';
    public const EXPIRED = 'expired';
    public const INVALID_STATE = 'invalid_state';
    public const NOT_FOUND = 'not_found';

    public function __construct(private readonly string $status)
    {
        if (! in_array($status, [
            self::RELEASED,
            self::ALREADY_RELEASED,
            self::NOT_OWNER,
            self::VERSION_MISMATCH,
            self::EXPIRED,
            self::INVALID_STATE,
            self::NOT_FOUND,
        ], true)) {
            throw new InvalidArgumentException('Resultado de liberacion no valido.');
        }
    }

    public function status(): string { return $this->status; }
    public function released(): bool { return $this->status === self::RELEASED; }
}
