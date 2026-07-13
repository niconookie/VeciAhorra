<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Models;

use InvalidArgumentException;

final class NormalizedFinancialApproval
{
    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly int $responseCode,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $buyOrder,
        public readonly string $financialSessionId,
        public readonly string $transactionDate,
        public readonly string $safeFinancialReference,
        public readonly string $tokenHash,
        public readonly ?string $paymentTypeCode,
        public readonly string $correlationId,
        public readonly string $origin
    ) {
        if (
            $provider !== 'webpay_plus'
            || ! in_array($status, [
                'AUTHORIZED', 'FAILED', 'REVERSED', 'NULLIFIED',
                'PARTIALLY_NULLIFIED', 'CAPTURED',
            ], true)
            || $amount <= 0
            || $currency !== 'CLP'
            || preg_match('/^VA[A-F0-9]{24}$/D', $buyOrder) !== 1
            || preg_match('/^VA-[A-F0-9]{58}$/D', $financialSessionId) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/D', $transactionDate) !== 1
            || preg_match('/^sha256:[a-f0-9]{12,56}$/D', $safeFinancialReference) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $tokenHash) !== 1
            || preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $correlationId) !== 1
            || ! in_array($origin, [
                'webpay_return', 'manual_recovery', 'internal_retry', 'test',
            ], true)
            || ($paymentTypeCode !== null
                && preg_match('/^[A-Z]{2,4}$/D', $paymentTypeCode) !== 1)
        ) {
            throw new InvalidArgumentException(
                'El resultado financiero normalizado no es valido.'
            );
        }
    }

    public function isApproved(): bool
    {
        return $this->status === 'AUTHORIZED' && $this->responseCode === 0;
    }
}
