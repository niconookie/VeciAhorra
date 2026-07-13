<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Service;

use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentReconciliationTechnicalEvaluatorInterface;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationReferences;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\TechnicalReconciliationResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\Exception\InconsistentReconciliationEvidence;
use VeciAhorra\Modules\Payments\Reconciliation\Support\FinancialFingerprint;

final class PaymentReconciliationTechnicalEvaluator implements
    PaymentReconciliationTechnicalEvaluatorInterface
{
    public function evaluate(
        ReconciliationReferences $reconciliation,
        DurablePaymentOrigin $origin,
        ValidatedFinancialResult $financialResult
    ): TechnicalReconciliationResult {
        $financial = $financialResult->components();
        $tokenHash = $origin->tokenHash();
        $consistent = $reconciliation->provider() === 'webpay_plus'
            && $reconciliation->fingerprintVersion() === FinancialFingerprint::VERSION
            && hash_equals(
                $reconciliation->financialFingerprint(),
                $financialResult->fingerprint()
            )
            && hash_equals($reconciliation->originKey(), $origin->originKey())
            && $financial->environment() === $origin->environment()
            && hash_equals(
                $financial->merchantIdentityHash(),
                $origin->merchantIdentityHash()
            )
            && $financial->amountClp() === $origin->amountClp()
            && $financial->buyOrder() === $origin->buyOrder()
            && $financial->financialSessionId() === $origin->financialSessionId()
            && $origin->currency() === 'CLP'
            && $tokenHash !== null
            && hash_equals($tokenHash, $financialResult->tokenHash());

        if (! $consistent) {
            throw new InconsistentReconciliationEvidence(
                'La evidencia durable no coincide con su contexto de origen.'
            );
        }

        return new TechnicalReconciliationResult(
            $reconciliation->id(),
            'technical_' . $financialResult->financialStatus(),
            $financialResult->fingerprint(),
            $origin->originKey()
        );
    }
}
