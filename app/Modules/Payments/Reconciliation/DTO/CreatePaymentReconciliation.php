<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\DTO;

use InvalidArgumentException;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Support\ReconciliationValidation;

final class CreatePaymentReconciliation
{
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
        if ($webpayReturnId <= 0 || $originContextId <= 0 || $attemptCount < 0) {
            throw new InvalidArgumentException('Identidad durable no valida.');
        }

        if (! PaymentReconciliation::validStatus($status)) {
            throw new InvalidArgumentException(
                'reconciliation_status no es valido.'
            );
        }

        if (preg_match('/^pr_[a-f0-9]{32,56}$/D', $publicId) !== 1) {
            throw new InvalidArgumentException('public_id no es valido.');
        }

        if (
            $financialResult->fingerprint() === ''
            || $financialResult->components()->amountClp() !== $origin->amountClp()
            || $financialResult->components()->environment() !== $origin->environment()
            || $financialResult->components()->buyOrder() !== $origin->buyOrder()
            || $financialResult->components()->financialSessionId()
                !== $origin->financialSessionId()
        ) {
            throw new InvalidArgumentException(
                'Resultado financiero y origen no son coherentes.'
            );
        }

        $this->publicId = ReconciliationValidation::identifier($publicId, 'public_id');
        $this->webpayReturnId = $webpayReturnId;
        $this->originContextId = $originContextId;
        $this->financialResult = $financialResult;
        $this->origin = $origin;
        $this->status = $status;
        $this->businessResultCode = ReconciliationValidation::nullableCode(
            $businessResultCode,
            'business_result_code',
            50
        );
        $this->attemptCount = $attemptCount;
        $this->lastErrorCode = ReconciliationValidation::nullableCode(
            $lastErrorCode,
            'last_error_code',
            50
        );
        $this->lastErrorAt = ReconciliationValidation::nullableMysqlDate(
            $lastErrorAt,
            'last_error_at'
        );
        $this->createdAt = ReconciliationValidation::mysqlDate($createdAt, 'created_at');
        $this->lastAttemptAt = ReconciliationValidation::nullableMysqlDate(
            $lastAttemptAt,
            'last_attempt_at'
        );
        $this->reconciledAt = ReconciliationValidation::nullableMysqlDate(
            $reconciledAt,
            'reconciled_at'
        );
        $this->updatedAt = ReconciliationValidation::mysqlDate($updatedAt, 'updated_at');
    }

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
