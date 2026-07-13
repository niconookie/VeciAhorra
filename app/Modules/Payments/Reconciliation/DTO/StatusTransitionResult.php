<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\DTO;

use InvalidArgumentException;

final class StatusTransitionResult
{
    public const APPLIED = 'applied';
    public const ALREADY_APPLIED = 'already_applied';
    public const UNEXPECTED_STATE = 'unexpected_state';
    public const LEASE_EXPIRED = 'lease_expired';
    public const NOT_OWNER = 'not_owner';
    public const VERSION_MISMATCH = 'version_mismatch';
    public const NOT_FOUND = 'not_found';

    public function __construct(
        private readonly string $status,
        private readonly ?string $currentState = null
    ) {
        if (! in_array($status, [
            self::APPLIED,
            self::ALREADY_APPLIED,
            self::UNEXPECTED_STATE,
            self::LEASE_EXPIRED,
            self::NOT_OWNER,
            self::VERSION_MISMATCH,
            self::NOT_FOUND,
        ], true)) {
            throw new InvalidArgumentException('Resultado CAS no valido.');
        }
    }

    public function status(): string { return $this->status; }
    public function applied(): bool { return $this->status === self::APPLIED; }
    public function currentState(): ?string { return $this->currentState; }
}
