<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\DTO;

final class ReconciliationLeaseState
{
    public function __construct(
        private readonly int $reconciliationId,
        private readonly string $reconciliationStatus,
        private readonly int $attemptCount,
        private readonly ?string $owner,
        private readonly ?string $acquiredAt,
        private readonly ?string $expiresAt,
        private readonly int $version,
        private readonly string $databaseNow,
        private readonly bool $active,
        private readonly ?string $businessResultCode,
        private readonly ?string $lastErrorCode,
        private readonly ?string $lastErrorAt,
        private readonly ?string $reconciledAt
    ) {
    }

    public function reconciliationId(): int { return $this->reconciliationId; }
    public function reconciliationStatus(): string { return $this->reconciliationStatus; }
    public function attemptCount(): int { return $this->attemptCount; }
    public function owner(): ?string { return $this->owner; }
    public function acquiredAt(): ?string { return $this->acquiredAt; }
    public function expiresAt(): ?string { return $this->expiresAt; }
    public function version(): int { return $this->version; }
    public function databaseNow(): string { return $this->databaseNow; }
    public function active(): bool { return $this->active; }
    public function businessResultCode(): ?string { return $this->businessResultCode; }
    public function lastErrorCode(): ?string { return $this->lastErrorCode; }
    public function lastErrorAt(): ?string { return $this->lastErrorAt; }
    public function reconciledAt(): ?string { return $this->reconciledAt; }

    public function lease(): ?ReconciliationLease
    {
        if ($this->owner === null || $this->expiresAt === null || $this->version <= 0) {
            return null;
        }

        return new ReconciliationLease(
            $this->reconciliationId,
            $this->owner,
            $this->version,
            $this->expiresAt
        );
    }
}
