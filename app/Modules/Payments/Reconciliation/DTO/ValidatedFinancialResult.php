<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\DTO;

use InvalidArgumentException;
use VeciAhorra\Modules\Payments\Reconciliation\Support\FinancialFingerprint;
use VeciAhorra\Modules\Payments\Reconciliation\Support\ReconciliationValidation;

final class ValidatedFinancialResult
{
    private readonly string $publicResultId;
    private readonly string $financialStatus;
    private readonly string $operation;
    private readonly string $tokenHash;
    private readonly string $safeFinancialReference;
    private readonly FinancialFingerprintComponents $components;
    private readonly string $obtainedAt;
    private readonly string $validatedAt;

    public function __construct(
        string $publicResultId,
        string $financialStatus,
        string $operation,
        string $tokenHash,
        string $safeFinancialReference,
        FinancialFingerprintComponents $components,
        string $obtainedAt,
        string $validatedAt
    ) {
        if (! in_array($financialStatus, [
            'approved', 'rejected', 'aborted', 'inconsistent',
        ], true)) {
            throw new InvalidArgumentException('financial_status no es valido.');
        }

        if (! in_array($operation, ['commit', 'abort'], true)) {
            throw new InvalidArgumentException('operation no es valida.');
        }

        if (
            preg_match('/^wpr_[a-f0-9]{32,56}$/D', $publicResultId) !== 1
            || (($operation === 'abort') !== ($financialStatus === 'aborted'))
            || ($financialStatus === 'approved'
                && ($components->providerStatus() !== 'AUTHORIZED'
                    || $components->responseCode() !== 0
                    || $components->transactionDate() === null))
        ) {
            throw new InvalidArgumentException(
                'El resultado financiero validado no es coherente.'
            );
        }

        if (
            preg_match(
                '/^sha256:[a-f0-9]{12,56}$/D',
                $safeFinancialReference
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'safe_financial_reference no es valida.'
            );
        }

        $this->publicResultId = ReconciliationValidation::identifier(
            $publicResultId,
            'public_result_id'
        );
        $this->financialStatus = $financialStatus;
        $this->operation = $operation;
        $this->tokenHash = ReconciliationValidation::hash(
            $tokenHash,
            'token_hash'
        );
        $this->safeFinancialReference = $safeFinancialReference;
        $this->components = $components;
        $this->obtainedAt = ReconciliationValidation::mysqlDate(
            $obtainedAt,
            'financial_obtained_at'
        );
        $this->validatedAt = ReconciliationValidation::mysqlDate(
            $validatedAt,
            'financial_validated_at'
        );
    }

    public function fingerprint(): string
    {
        return FinancialFingerprint::make($this->components);
    }

    public function publicResultId(): string { return $this->publicResultId; }
    public function financialStatus(): string { return $this->financialStatus; }
    public function operation(): string { return $this->operation; }
    public function tokenHash(): string { return $this->tokenHash; }
    public function safeFinancialReference(): string { return $this->safeFinancialReference; }
    public function components(): FinancialFingerprintComponents { return $this->components; }
    public function obtainedAt(): string { return $this->obtainedAt; }
    public function validatedAt(): string { return $this->validatedAt; }
}
