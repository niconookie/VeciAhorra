<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Model;

use InvalidArgumentException;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\Support\ReconciliationValidation;

final class PaymentReconciliation
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_RETRYABLE = 'retryable';
    public const STATUS_PERMANENT_FAILURE = 'permanent_failure';
    public const STATUS_MANUAL_REVIEW = 'manual_review';

    private readonly int $id;
    private readonly string $publicId;
    private readonly int $webpayReturnId;
    private readonly int $originContextId;
    private readonly ValidatedFinancialResult $financialResult;
    private readonly DurablePaymentOrigin $origin;
    private readonly string $status;
    private readonly ?string $businessResultCode;
    private readonly int $attemptCount;
    private readonly ?string $lastErrorCode;
    private readonly ?string $lastErrorAt;
    private readonly string $createdAt;
    private readonly ?string $lastAttemptAt;
    private readonly ?string $reconciledAt;
    private readonly string $updatedAt;

    public function __construct(
        int $id,
        string $publicId,
        int $webpayReturnId,
        int $originContextId,
        ValidatedFinancialResult $financialResult,
        DurablePaymentOrigin $origin,
        string $status,
        ?string $businessResultCode,
        int $attemptCount,
        ?string $lastErrorCode,
        ?string $lastErrorAt,
        string $createdAt,
        ?string $lastAttemptAt,
        ?string $reconciledAt,
        string $updatedAt
    ) {
        if ($id <= 0 || $webpayReturnId <= 0 || $originContextId <= 0) {
            throw new InvalidArgumentException('Identidad de conciliacion no valida.');
        }

        if (! self::validStatus($status) || $attemptCount < 0) {
            throw new InvalidArgumentException('Estado de conciliacion no valido.');
        }

        $this->id = $id;
        $this->publicId = ReconciliationValidation::identifier($publicId, 'public_id');
        $this->webpayReturnId = $webpayReturnId;
        $this->originContextId = $originContextId;
        $this->financialResult = $financialResult;
        $this->origin = $origin;
        $this->status = $status;
        $this->businessResultCode = $businessResultCode;
        $this->attemptCount = $attemptCount;
        $this->lastErrorCode = $lastErrorCode;
        $this->lastErrorAt = $lastErrorAt;
        $this->createdAt = $createdAt;
        $this->lastAttemptAt = $lastAttemptAt;
        $this->reconciledAt = $reconciledAt;
        $this->updatedAt = $updatedAt;
    }

    public static function validStatus(string $status): bool
    {
        return in_array($status, [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_RETRYABLE,
            self::STATUS_PERMANENT_FAILURE,
            self::STATUS_MANUAL_REVIEW,
        ], true);
    }

    public function id(): int { return $this->id; }
    public function publicId(): string { return $this->publicId; }
    public function webpayReturnId(): int { return $this->webpayReturnId; }
    public function originContextId(): int { return $this->originContextId; }
    public function financialResult(): ValidatedFinancialResult { return $this->financialResult; }
    public function origin(): DurablePaymentOrigin { return $this->origin; }
    public function status(): string { return $this->status; }
    public function businessResultCode(): ?string { return $this->businessResultCode; }
    public function attemptCount(): int { return $this->attemptCount; }
    public function lastErrorCode(): ?string { return $this->lastErrorCode; }
    public function lastErrorAt(): ?string { return $this->lastErrorAt; }
    public function createdAt(): string { return $this->createdAt; }
    public function lastAttemptAt(): ?string { return $this->lastAttemptAt; }
    public function reconciledAt(): ?string { return $this->reconciledAt; }
    public function updatedAt(): string { return $this->updatedAt; }
}
