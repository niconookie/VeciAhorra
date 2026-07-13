<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\DTO;

use InvalidArgumentException;
use VeciAhorra\Modules\Payments\Reconciliation\Support\ReconciliationValidation;

final class FinancialFingerprintComponents
{
    public const SCHEMA = 'webpay-financial-v1';
    public const PROVIDER = 'webpay_plus';

    private readonly string $environment;
    private readonly string $merchantIdentityHash;
    private readonly string $providerStatus;
    private readonly int $responseCode;
    private readonly int $amountClp;
    private readonly string $buyOrder;
    private readonly string $financialSessionId;
    private readonly ?string $transactionDate;
    private readonly ?string $authorizationHash;
    private readonly ?string $paymentTypeCode;
    private readonly ?int $installmentsNumber;
    private readonly ?string $accountingDate;

    public function __construct(
        string $environment,
        string $merchantIdentityHash,
        string $providerStatus,
        int $responseCode,
        mixed $amountClp,
        string $buyOrder,
        string $financialSessionId,
        ?string $transactionDate,
        ?string $authorizationHash,
        ?string $paymentTypeCode,
        ?int $installmentsNumber,
        ?string $accountingDate
    ) {
        if (! in_array($environment, ['integration', 'production'], true)) {
            throw new InvalidArgumentException('environment no es valido.');
        }

        $status = strtoupper(trim($providerStatus));

        if (
            ! in_array($status, [
                'AUTHORIZED', 'FAILED', 'REVERSED', 'NULLIFIED',
                'PARTIALLY_NULLIFIED', 'CAPTURED',
            ], true)
        ) {
            throw new InvalidArgumentException('provider_status no es valido.');
        }

        if ($installmentsNumber !== null && $installmentsNumber < 0) {
            throw new InvalidArgumentException(
                'installments_number no es valido.'
            );
        }

        $this->environment = $environment;
        $this->merchantIdentityHash = ReconciliationValidation::hash(
            $merchantIdentityHash,
            'merchant_identity_hash'
        );
        $this->providerStatus = $status;
        $this->responseCode = $responseCode;
        $this->amountClp = ReconciliationValidation::clp($amountClp);
        $buyOrder = trim($buyOrder);
        $financialSessionId = trim($financialSessionId);

        if (preg_match('/^VA[A-F0-9]{24}$/D', $buyOrder) !== 1) {
            throw new InvalidArgumentException('buy_order no es valido.');
        }

        if (preg_match('/^VA-[A-F0-9]{58}$/D', $financialSessionId) !== 1) {
            throw new InvalidArgumentException(
                'financial_session_id no es valido.'
            );
        }

        $this->buyOrder = $buyOrder;
        $this->financialSessionId = $financialSessionId;
        $this->transactionDate = ReconciliationValidation::utcDate(
            $transactionDate
        );
        $this->authorizationHash = $authorizationHash === null
            ? null
            : ReconciliationValidation::hash(
                $authorizationHash,
                'authorization_hash'
            );
        $this->paymentTypeCode = ReconciliationValidation::nullableCode(
            $paymentTypeCode === null ? null : strtoupper(trim($paymentTypeCode)),
            'payment_type_code',
            4
        );
        $this->installmentsNumber = $installmentsNumber;
        $this->accountingDate = ReconciliationValidation::nullableCode(
            $accountingDate === null ? null : trim($accountingDate),
            'accounting_date',
            10
        );
    }

    public static function authorizationHash(?string $code): ?string
    {
        return $code === null ? null : hash('sha256', $code);
    }

    /** @return array<string, int|string|null> */
    public function canonicalData(): array
    {
        return [
            'schema' => self::SCHEMA,
            'provider' => self::PROVIDER,
            'environment' => $this->environment,
            'merchant_identity_hash' => $this->merchantIdentityHash,
            'provider_status' => $this->providerStatus,
            'response_code' => $this->responseCode,
            'amount_clp' => $this->amountClp,
            'currency' => 'CLP',
            'buy_order' => $this->buyOrder,
            'financial_session_id' => $this->financialSessionId,
            'transaction_date' => $this->transactionDate,
            'authorization_hash' => $this->authorizationHash,
            'payment_type_code' => $this->paymentTypeCode,
            'installments_number' => $this->installmentsNumber,
            'accounting_date' => $this->accountingDate,
        ];
    }

    public function environment(): string { return $this->environment; }
    public function merchantIdentityHash(): string { return $this->merchantIdentityHash; }
    public function providerStatus(): string { return $this->providerStatus; }
    public function responseCode(): int { return $this->responseCode; }
    public function amountClp(): int { return $this->amountClp; }
    public function buyOrder(): string { return $this->buyOrder; }
    public function financialSessionId(): string { return $this->financialSessionId; }
    public function transactionDate(): ?string { return $this->transactionDate; }
    public function authorizationHashValue(): ?string { return $this->authorizationHash; }
    public function paymentTypeCode(): ?string { return $this->paymentTypeCode; }
    public function installmentsNumber(): ?int { return $this->installmentsNumber; }
    public function accountingDate(): ?string { return $this->accountingDate; }
}
