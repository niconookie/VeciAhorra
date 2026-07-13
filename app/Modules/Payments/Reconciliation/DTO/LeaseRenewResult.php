<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\DTO;

use InvalidArgumentException;

final class LeaseRenewResult
{
    public const RENEWED = 'renewed';
    public const EXPIRED = 'expired';
    public const NOT_OWNER = 'not_owner';
    public const VERSION_MISMATCH = 'version_mismatch';
    public const INVALID_STATE = 'invalid_state';
    public const NOT_FOUND = 'not_found';

    public function __construct(
        private readonly string $status,
        private readonly ?string $expiresAt = null
    ) {
        if (! in_array($status, [
            self::RENEWED,
            self::EXPIRED,
            self::NOT_OWNER,
            self::VERSION_MISMATCH,
            self::INVALID_STATE,
            self::NOT_FOUND,
        ], true)) {
            throw new InvalidArgumentException('Resultado de renovacion no valido.');
        }
    }

    public function status(): string { return $this->status; }
    public function renewed(): bool { return $this->status === self::RENEWED; }
    public function expiresAt(): ?string { return $this->expiresAt; }
}
