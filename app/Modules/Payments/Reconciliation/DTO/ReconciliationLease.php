<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\DTO;

use InvalidArgumentException;
use VeciAhorra\Modules\Payments\Reconciliation\Support\ReconciliationValidation;

final class ReconciliationLease
{
    public function __construct(
        private readonly int $reconciliationId,
        private readonly string $owner,
        private readonly int $version,
        private readonly string $expiresAt
    ) {
        if (
            $reconciliationId <= 0
            || preg_match('/^worker_[a-f0-9]{32}$/D', $owner) !== 1
            || $version <= 0
            || $expiresAt === ''
        ) {
            throw new InvalidArgumentException('La autoridad del lease no es valida.');
        }

        ReconciliationValidation::mysqlDate($expiresAt, 'lease_expires_at');
    }

    public function reconciliationId(): int { return $this->reconciliationId; }
    public function owner(): string { return $this->owner; }
    public function version(): int { return $this->version; }
    public function expiresAt(): string { return $this->expiresAt; }
}
