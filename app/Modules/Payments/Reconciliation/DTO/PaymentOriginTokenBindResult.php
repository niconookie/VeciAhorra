<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\DTO;

use InvalidArgumentException;

final class PaymentOriginTokenBindResult
{
    public const BOUND = 'bound';
    public const ALREADY_BOUND = 'already_bound';
    public const TOKEN_CONFLICT = 'token_conflict';
    public const ATTEMPT_MISMATCH = 'attempt_mismatch';
    public const EXPIRED = 'expired';
    public const NOT_FOUND = 'not_found';

    public function __construct(private readonly string $status)
    {
        if (! in_array($status, [
            self::BOUND,
            self::ALREADY_BOUND,
            self::TOKEN_CONFLICT,
            self::ATTEMPT_MISMATCH,
            self::EXPIRED,
            self::NOT_FOUND,
        ], true)) {
            throw new InvalidArgumentException('Resultado de bind no valido.');
        }
    }

    public function status(): string { return $this->status; }
    public function bound(): bool
    {
        return in_array($this->status, [self::BOUND, self::ALREADY_BOUND], true);
    }
}
