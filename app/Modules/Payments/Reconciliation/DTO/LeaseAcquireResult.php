<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\DTO;

use InvalidArgumentException;

final class LeaseAcquireResult
{
    public const ACQUIRED = 'acquired';
    public const BUSY = 'busy';
    public const NOT_CLAIMABLE = 'not_claimable';
    public const ATTEMPTS_EXHAUSTED = 'attempts_exhausted';
    public const NOT_FOUND = 'not_found';

    public function __construct(
        private readonly string $status,
        private readonly ?ReconciliationLease $lease = null
    ) {
        if (! in_array($status, [
            self::ACQUIRED,
            self::BUSY,
            self::NOT_CLAIMABLE,
            self::ATTEMPTS_EXHAUSTED,
            self::NOT_FOUND,
        ], true)) {
            throw new InvalidArgumentException('Resultado de adquisicion no valido.');
        }

        if (($status === self::ACQUIRED) !== ($lease !== null)) {
            throw new InvalidArgumentException(
                'La adquisicion no contiene una autoridad coherente.'
            );
        }
    }

    public function status(): string { return $this->status; }
    public function acquired(): bool { return $this->status === self::ACQUIRED; }
    public function lease(): ?ReconciliationLease { return $this->lease; }
    public function owner(): ?string { return $this->lease?->owner(); }
    public function expiresAt(): ?string { return $this->lease?->expiresAt(); }
    public function leaseVersion(): ?int { return $this->lease?->version(); }
}
