<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\WooCommerce;

use Throwable;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentCompletionHandlerInterface;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentCompletionOutcomeInterface;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationReferences;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\TechnicalReconciliationResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\Support\WooCommerceTransactionReferenceFactory;
use VeciAhorra\Modules\Payments\Reconciliation\Support\WordPressSiteScope;
use VeciAhorra\Modules\Payments\WooCommerce\Contracts\WooCommerceOrderRepositoryInterface;

final class WooCommercePaymentCompletionHandler implements
    PaymentCompletionHandlerInterface
{
    public const COMPLETION_STARTED_META =
        '_veciahorra_payment_completion_started_v1';
    public const COMPLETION_ENTERED_META =
        '_veciahorra_payment_completion_entered_v1';
    public const RECONCILED_FINGERPRINT_META =
        '_veciahorra_reconciled_fingerprint_v1';

    private const GATEWAY_ID = 'veciahorra_webpay_plus';

    public function __construct(
        private readonly WooCommerceOrderRepositoryInterface $orders =
            new WooCommerceOrderRepository()
    ) {
    }

    public function supports(DurablePaymentOrigin $origin): bool
    {
        return $origin->origin() === DurablePaymentOrigin::ORIGIN_WOOCOMMERCE;
    }

    public function complete(
        ReconciliationReferences $reconciliation,
        DurablePaymentOrigin $origin,
        ValidatedFinancialResult $financialResult,
        TechnicalReconciliationResult $technicalResult
    ): PaymentCompletionOutcomeInterface {
        $orderId = $this->orderId($origin->originResourceId());

        if ($orderId === null) {
            return $this->failure(
                WooCommercePaymentCompletionResult::ORDER_MISMATCH,
                1,
                'order_id_invalid'
            );
        }

        if (! $this->financialIdentityIsValid(
            $reconciliation,
            $origin,
            $financialResult,
            $technicalResult
        )) {
            return $this->failure(
                WooCommercePaymentCompletionResult::ORDER_MISMATCH,
                $orderId,
                'financial_identity_mismatch'
            );
        }

        $order = $this->orders->find($orderId);

        if ($order === null) {
            return $this->failure(
                WooCommercePaymentCompletionResult::ORDER_NOT_FOUND,
                $orderId,
                'order_not_found'
            );
        }

        $precondition = $this->validateOrder(
            $order,
            $orderId,
            $reconciliation->originContextId(),
            $origin
        );

        if ($precondition !== null) {
            return $precondition;
        }

        $reference = WooCommerceTransactionReferenceFactory::
            fromFinancialFingerprint($financialResult->fingerprint());
        $inspection = $this->inspect($order, $origin, $reference);

        if ($inspection === 'same') {
            return $this->persistVerifiedFingerprint(
                $order,
                $origin,
                $reconciliation->originContextId(),
                $reference,
                WooCommercePaymentCompletionResult::ALREADY_APPLIED_SAME_PAYMENT,
                'same_payment_already_durable'
            );
        }

        if ($inspection === 'different') {
            return $this->failure(
                WooCommercePaymentCompletionResult::PAYMENT_ALREADY_DIFFERENT,
                $orderId,
                'different_payment_evidence'
            );
        }

        if ($inspection === 'entered_no_effect') {
            return $this->failure(
                WooCommercePaymentCompletionResult::PAYMENT_COMPLETION_FAILED,
                $orderId,
                'payment_completed_without_effect'
            );
        }

        if ($inspection === 'unverified') {
            return $this->failure(
                WooCommercePaymentCompletionResult::PAYMENT_RESULT_UNVERIFIED,
                $orderId,
                'payment_effect_unverified'
            );
        }

        if (! (bool) $order->needs_payment()) {
            return $this->failure(
                WooCommercePaymentCompletionResult::PAYMENT_COMPLETION_FAILED,
                $orderId,
                'order_not_payable'
            );
        }

        try {
            $order->update_meta_data(
                self::COMPLETION_STARTED_META,
                $financialResult->fingerprint()
            );
            $order->save();
        } catch (Throwable) {
            return $this->failure(
                WooCommercePaymentCompletionResult::PAYMENT_COMPLETION_FAILED,
                $orderId,
                'completion_marker_failed'
            );
        }

        $markedOrder = $this->orders->find($orderId);

        if (
            $markedOrder === null
            || ! hash_equals(
                $financialResult->fingerprint(),
                (string) $markedOrder->get_meta(self::COMPLETION_STARTED_META)
            )
        ) {
            return $this->failure(
                WooCommercePaymentCompletionResult::PAYMENT_RESULT_UNVERIFIED,
                $orderId,
                'completion_marker_unverified'
            );
        }

        $markedPrecondition = $this->validateOrder(
            $markedOrder,
            $orderId,
            $reconciliation->originContextId(),
            $origin
        );

        if ($markedPrecondition !== null) {
            return $markedPrecondition;
        }

        $this->invokePaymentComplete(
            $markedOrder,
            $orderId,
            $reference,
            $financialResult->fingerprint()
        );

        unset($order, $markedOrder);
        $persisted = $this->orders->find($orderId);

        if ($persisted === null) {
            return $this->failure(
                WooCommercePaymentCompletionResult::PAYMENT_RESULT_UNVERIFIED,
                $orderId,
                'post_completion_order_missing'
            );
        }

        $postInspection = $this->inspect($persisted, $origin, $reference);

        if ($postInspection === 'different') {
            return $this->failure(
                WooCommercePaymentCompletionResult::PAYMENT_ALREADY_DIFFERENT,
                $orderId,
                'post_completion_different_payment'
            );
        }

        if (in_array($postInspection, ['prepared', 'entered_no_effect'], true)) {
            return $this->failure(
                WooCommercePaymentCompletionResult::PAYMENT_COMPLETION_FAILED,
                $orderId,
                'payment_completion_without_effect'
            );
        }

        if ($postInspection !== 'same') {
            return $this->failure(
                WooCommercePaymentCompletionResult::PAYMENT_RESULT_UNVERIFIED,
                $orderId,
                'post_completion_evidence_mismatch'
            );
        }

        return $this->persistVerifiedFingerprint(
            $persisted,
            $origin,
            $reconciliation->originContextId(),
            $reference,
            WooCommercePaymentCompletionResult::APPLIED_NOW,
            'payment_applied_and_verified'
        );
    }

    private function financialIdentityIsValid(
        ReconciliationReferences $reconciliation,
        DurablePaymentOrigin $origin,
        ValidatedFinancialResult $financialResult,
        TechnicalReconciliationResult $technicalResult
    ): bool {
        $components = $financialResult->components();
        $tokenHash = $origin->tokenHash();

        return $this->supports($origin)
            && $origin->gatewayId() === self::GATEWAY_ID
            && $origin->currency() === 'CLP'
            && $financialResult->financialStatus() === 'approved'
            && $financialResult->operation() === 'commit'
            && $components->providerStatus() === 'AUTHORIZED'
            && $components->responseCode() === 0
            && $components->environment() === $origin->environment()
            && hash_equals(
                $components->merchantIdentityHash(),
                $origin->merchantIdentityHash()
            )
            && $components->amountClp() === $origin->amountClp()
            && $components->buyOrder() === $origin->buyOrder()
            && $components->financialSessionId() === $origin->financialSessionId()
            && $tokenHash !== null
            && hash_equals($tokenHash, $financialResult->tokenHash())
            && hash_equals(
                $reconciliation->financialFingerprint(),
                $financialResult->fingerprint()
            )
            && hash_equals($reconciliation->originKey(), $origin->originKey())
            && hash_equals(
                $technicalResult->financialFingerprint(),
                $financialResult->fingerprint()
            )
            && $technicalResult->resultCode() === 'technical_approved'
            && $technicalResult->reconciliationId() === $reconciliation->id()
            && hash_equals($technicalResult->originKey(), $origin->originKey());
    }

    private function validateOrder(
        object $order,
        int $orderId,
        int $originContextId,
        DurablePaymentOrigin $origin
    ): ?WooCommercePaymentCompletionOutcome {
        try {
            if (
                WordPressSiteScope::current() !== $origin->siteScope()
                || (int) $order->get_id() !== $orderId
                || (string) $order->get_meta(
                    WooCommercePaymentAttemptService::ORIGIN_META
                ) !== (string) $originContextId
                || ! hash_equals(
                    $origin->paymentAttemptId(),
                    (string) $order->get_meta(
                        WooCommercePaymentAttemptService::ATTEMPT_META
                    )
                )
            ) {
                return $this->failure(
                    WooCommercePaymentCompletionResult::ORDER_MISMATCH,
                    $orderId,
                    'order_identity_mismatch'
                );
            }

            if (
                (string) $order->get_payment_method() !== $origin->gatewayId()
                || (string) $order->get_meta(
                    WooCommercePaymentAttemptService::GATEWAY_META
                ) !== $origin->gatewayId()
            ) {
                return $this->failure(
                    WooCommercePaymentCompletionResult::GATEWAY_MISMATCH,
                    $orderId,
                    'gateway_mismatch'
                );
            }

            if (
                strtoupper((string) $order->get_currency()) !== 'CLP'
                || $this->integerClp($order->get_total()) !== $origin->amountClp()
            ) {
                return $this->failure(
                    WooCommercePaymentCompletionResult::AMOUNT_MISMATCH,
                    $orderId,
                    'amount_or_currency_mismatch'
                );
            }
        } catch (Throwable) {
            return $this->failure(
                WooCommercePaymentCompletionResult::ORDER_MISMATCH,
                $orderId,
                'order_read_failed'
            );
        }

        return null;
    }

    private function inspect(
        object $order,
        DurablePaymentOrigin $origin,
        string $reference
    ): string {
        try {
            $paid = (bool) $order->is_paid();
            $transactionId = trim((string) $order->get_transaction_id());
            $paidAt = $order->get_date_paid('edit');
            $started = (string) $order->get_meta(self::COMPLETION_STARTED_META);
            $entered = (string) $order->get_meta(self::COMPLETION_ENTERED_META);
            $reconciled = (string) $order->get_meta(
                self::RECONCILED_FINGERPRINT_META
            );
            $fingerprint = substr($reference, strlen('va-wp-v1-'));
            $attemptMatches = hash_equals(
                $origin->paymentAttemptId(),
                (string) $order->get_meta(
                    WooCommercePaymentAttemptService::ATTEMPT_META
                )
            );

            if ($paid) {
                if (
                    $paidAt !== null
                    && $transactionId === $reference
                    && $attemptMatches
                    && ($started === '' || hash_equals($fingerprint, $started))
                    && ($entered === '' || hash_equals($fingerprint, $entered))
                    && ($reconciled === '' || hash_equals($fingerprint, $reconciled))
                ) {
                    return 'same';
                }

                return 'different';
            }

            if (
                $transactionId !== ''
                || $reconciled !== ''
                || $entered !== ''
                || $started !== ''
            ) {
                if (
                    ($transactionId !== '' && $transactionId !== $reference)
                    || ($reconciled !== ''
                        && ! hash_equals($fingerprint, $reconciled))
                    || ($entered !== '' && ! hash_equals($fingerprint, $entered))
                    || ($started !== '' && ! hash_equals($fingerprint, $started))
                ) {
                    return 'different';
                }

                if ($transactionId !== '' || $reconciled !== '') {
                    return 'unverified';
                }

                if ($entered !== '') {
                    return 'entered_no_effect';
                }

                return 'prepared';
            }
        } catch (Throwable) {
            return 'unverified';
        }

        return 'not_applied';
    }

    private function invokePaymentComplete(
        object $order,
        int $orderId,
        string $reference,
        string $fingerprint
    ): void {
        $entered = function (
            int $observedOrderId,
            string $observedReference = ''
        ) use ($orderId, $reference, $fingerprint): void {
            if (
                $observedOrderId !== $orderId
                || $observedReference !== $reference
            ) {
                return;
            }

            $fresh = $this->orders->find($orderId);

            if ($fresh === null) {
                throw new \RuntimeException(
                    'No fue posible registrar la entrada de completitud.'
                );
            }

            $fresh->update_meta_data(self::COMPLETION_ENTERED_META, $fingerprint);
            $fresh->save();
            unset($fresh);
            $verified = $this->orders->find($orderId);

            if (
                $verified === null
                || ! hash_equals(
                    $fingerprint,
                    (string) $verified->get_meta(self::COMPLETION_ENTERED_META)
                )
            ) {
                throw new \RuntimeException(
                    'No fue posible verificar la entrada de completitud.'
                );
            }
        };
        $hookRegistered = function_exists('add_action')
            && function_exists('remove_action');

        if ($hookRegistered) {
            add_action(
                'woocommerce_pre_payment_complete',
                $entered,
                PHP_INT_MIN,
                2
            );
        }

        try {
            $order->payment_complete($reference);
        } catch (Throwable) {
            // A hook may have persisted the effect before propagating an Error.
        } finally {
            if ($hookRegistered) {
                remove_action(
                    'woocommerce_pre_payment_complete',
                    $entered,
                    PHP_INT_MIN
                );
            }
        }
    }

    private function persistVerifiedFingerprint(
        object $order,
        DurablePaymentOrigin $origin,
        int $originContextId,
        string $reference,
        string $result,
        string $diagnosticCode
    ): WooCommercePaymentCompletionOutcome {
        $orderId = (int) $order->get_id();
        $fingerprint = substr($reference, strlen('va-wp-v1-'));

        try {
            $order->update_meta_data(
                self::RECONCILED_FINGERPRINT_META,
                $fingerprint
            );
            $order->save();
        } catch (Throwable) {
            return $this->failure(
                WooCommercePaymentCompletionResult::PAYMENT_RESULT_UNVERIFIED,
                $orderId,
                'verified_marker_failed'
            );
        }

        unset($order);
        $verified = $this->orders->find($orderId);

        if (
            $verified === null
            || $this->validateOrder(
                $verified,
                $orderId,
                $originContextId,
                $origin
            ) !== null
            || $this->inspect($verified, $origin, $reference) !== 'same'
            || ! hash_equals(
                $fingerprint,
                (string) $verified->get_meta(
                    self::RECONCILED_FINGERPRINT_META
                )
            )
        ) {
            return $this->failure(
                WooCommercePaymentCompletionResult::PAYMENT_RESULT_UNVERIFIED,
                $orderId,
                'durable_verification_failed'
            );
        }

        return new WooCommercePaymentCompletionOutcome(
            $result,
            $orderId,
            $reference,
            $diagnosticCode
        );
    }

    private function integerClp(mixed $amount): ?int
    {
        if (! is_string($amount)) {
            return null;
        }

        if (preg_match('/^([1-9]\d*)(?:\.00)?$/D', $amount, $matches) !== 1) {
            return null;
        }

        $value = filter_var($matches[1], FILTER_VALIDATE_INT);

        return $value !== false && $value > 0 ? $value : null;
    }

    private function orderId(string $value): ?int
    {
        if (preg_match('/^[1-9]\d*$/D', $value) !== 1) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id !== false && $id > 0 ? $id : null;
    }

    private function failure(
        string $result,
        int $orderId,
        string $diagnosticCode
    ): WooCommercePaymentCompletionOutcome {
        return new WooCommercePaymentCompletionOutcome(
            $result,
            $orderId,
            null,
            $diagnosticCode
        );
    }
}
