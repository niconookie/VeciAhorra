<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Service;

use VeciAhorra\Modules\Payments\Gateway\WebpayCommitResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\CreatePaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\FinancialFingerprintComponents;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\MaterializedReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\Exception\DuplicateReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Exception\ReconciliationMaterializationConflict;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\ValidatedFinancialResultRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Support\WooCommerceTransactionReferenceFactory;

final class WebpayReconciliationMaterializer
{
    public function __construct(
        private readonly ValidatedFinancialResultRepository $financialResults = new ValidatedFinancialResultRepository(),
        private readonly PaymentReconciliationRepository $reconciliations = new PaymentReconciliationRepository()
    ) {
    }

    public function materialize(
        string $tokenHash,
        DurablePaymentOrigin $origin,
        WebpayCommitResult $commit,
        string $financialStatus
    ): MaterializedReconciliation {
        if (
            $origin->tokenHash() === null
            || ! hash_equals($origin->tokenHash(), $tokenHash)
            || ! hash_equals($origin->buyOrder(), $commit->buyOrder)
            || ! hash_equals($origin->financialSessionId(), $commit->sessionId)
            || $origin->amountClp() !== $commit->amount
            || (($financialStatus === 'approved') !== $commit->isApproved())
            || ! in_array($financialStatus, ['approved', 'rejected'], true)
        ) {
            throw new ReconciliationMaterializationConflict(
                'La evidencia financiera no corresponde al origen durable.'
            );
        }

        $now = current_time('mysql', true);
        $components = new FinancialFingerprintComponents(
            $origin->environment(),
            $origin->merchantIdentityHash(),
            $commit->status,
            $commit->responseCode,
            $commit->amount,
            $commit->buyOrder,
            $commit->sessionId,
            $commit->transactionDate,
            FinancialFingerprintComponents::authorizationHash(
                $commit->authorizationCode
            ),
            $commit->paymentTypeCode,
            $commit->installmentsNumber,
            $commit->accountingDate
        );
        $financial = new ValidatedFinancialResult(
            'wpr_' . bin2hex(random_bytes(20)),
            $financialStatus,
            'commit',
            $tokenHash,
            'sha256:' . substr($tokenHash, 0, 12),
            $components,
            $now,
            $now
        );
        $returnId = $this->financialResults->materializeExisting(
            $tokenHash,
            $financial
        );

        try {
            $reconciliationId = $this->reconciliations->create(
                new CreatePaymentReconciliation(
                    'pr_' . bin2hex(random_bytes(20)),
                    $returnId,
                    $this->originId($origin),
                    $financial,
                    $origin,
                    PaymentReconciliation::STATUS_PENDING,
                    null,
                    0,
                    null,
                    null,
                    $now,
                    null,
                    null,
                    $now
                )
            );
        } catch (DuplicateReconciliation) {
            $stored = $this->reconciliations->findByFingerprint(
                $financial->fingerprint()
            );

            if (
                $stored === null
                || $stored->webpayReturnId() !== $returnId
                || ! hash_equals($stored->origin()->originKey(), $origin->originKey())
            ) {
                throw new ReconciliationMaterializationConflict(
                    'La conciliacion durable entra en conflicto.'
                );
            }

            $reconciliationId = $stored->id();
        }

        return new MaterializedReconciliation(
            $returnId,
            $reconciliationId,
            WooCommerceTransactionReferenceFactory::fromFinancialFingerprint(
                $financial->fingerprint()
            )
        );
    }

    public function resume(
        string $tokenHash,
        DurablePaymentOrigin $origin
    ): ?MaterializedReconciliation {
        if (
            $origin->tokenHash() === null
            || ! hash_equals($origin->tokenHash(), $tokenHash)
        ) {
            throw new ReconciliationMaterializationConflict(
                'El token seguro no corresponde al origen durable.'
            );
        }

        $financial = $this->financialResults->findByTokenHash($tokenHash);

        if ($financial === null) {
            return null;
        }

        $stored = $this->reconciliations->findByFingerprint(
            $financial->fingerprint()
        );

        if ($stored === null) {
            $now = current_time('mysql', true);
            $returnId = $this->returnId($tokenHash);
            try {
                $reconciliationId = $this->reconciliations->create(
                    new CreatePaymentReconciliation(
                        'pr_' . bin2hex(random_bytes(20)),
                        $returnId,
                        $this->originId($origin),
                        $financial,
                        $origin,
                        PaymentReconciliation::STATUS_PENDING,
                        null,
                        0,
                        null,
                        null,
                        $now,
                        null,
                        null,
                        $now
                    )
                );
            } catch (DuplicateReconciliation) {
                $stored = $this->reconciliations->findByFingerprint(
                    $financial->fingerprint()
                );

                if (
                    $stored === null
                    || $stored->webpayReturnId() !== $returnId
                    || ! hash_equals(
                        $stored->origin()->originKey(),
                        $origin->originKey()
                    )
                ) {
                    throw new ReconciliationMaterializationConflict(
                        'La reanudacion durable entra en conflicto.'
                    );
                }

                $reconciliationId = $stored->id();
            }
        } else {
            if (! hash_equals($stored->origin()->originKey(), $origin->originKey())) {
                throw new ReconciliationMaterializationConflict(
                    'El resultado pertenece a otro origen durable.'
                );
            }

            $returnId = $stored->webpayReturnId();
            $reconciliationId = $stored->id();
        }

        return new MaterializedReconciliation(
            $returnId,
            $reconciliationId,
            WooCommerceTransactionReferenceFactory::fromFinancialFingerprint(
                $financial->fingerprint()
            )
        );
    }

    private function originId(DurablePaymentOrigin $origin): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . $wpdb->prefix . \VeciAhorra\Core\Config::TABLE_PREFIX
            . 'payment_origin_contexts WHERE origin_key = %s LIMIT 1',
            $origin->originKey()
        ));
    }

    private function returnId(string $tokenHash): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . $wpdb->prefix . \VeciAhorra\Core\Config::TABLE_PREFIX
            . 'webpay_returns WHERE token_hash = %s LIMIT 1',
            $tokenHash
        ));
    }
}
