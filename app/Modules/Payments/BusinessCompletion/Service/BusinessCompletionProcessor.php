<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\BusinessCompletion\Service;

use Throwable;
use VeciAhorra\Modules\Checkout\Models\Checkout;
use VeciAhorra\Modules\Checkout\Repository\CheckoutOrderRepository;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;
use VeciAhorra\Modules\Payments\BusinessCompletion\DTO\BusinessCompletionResult;
use VeciAhorra\Modules\Payments\BusinessCompletion\Exception\BusinessCompletionFailure;
use VeciAhorra\Modules\Payments\BusinessCompletion\Repository\BusinessCompletionRepository;
use VeciAhorra\Modules\Payments\Models\PaymentSession;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationRepository;
use VeciAhorra\Modules\Payments\Repository\PaymentRepository;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;

final class BusinessCompletionProcessor
{
    public function __construct(
        private readonly BusinessCompletionRepository $completions = new BusinessCompletionRepository(),
        private readonly PaymentReconciliationRepository $reconciliations = new PaymentReconciliationRepository(),
        private readonly CheckoutRepository $checkouts = new CheckoutRepository(),
        private readonly CheckoutOrderRepository $checkoutOrders = new CheckoutOrderRepository(),
        private readonly PaymentSessionRepository $sessions = new PaymentSessionRepository(),
        private readonly PaymentRepository $payments = new PaymentRepository(),
        private readonly OrderRepository $orders = new OrderRepository()
    ) {
    }

    public function process(int $reconciliationId, string $workerId, int $leaseSeconds = 30): BusinessCompletionResult
    {
        if ($reconciliationId <= 0 || preg_match('/^business_[a-f0-9]{32}$/D', $workerId) !== 1 || $leaseSeconds < 5) {
            throw new \InvalidArgumentException('La solicitud de finalizacion no es valida.');
        }
        try {
            $reconciliation = $this->reconciliations->find($reconciliationId);
            if ($reconciliation === null) {
                return new BusinessCompletionResult(BusinessCompletionResult::PERMANENT_FAILURE, 'reconciliation_missing', $reconciliationId);
            }
            if ($reconciliation->status() !== PaymentReconciliation::STATUS_COMPLETED) {
                return new BusinessCompletionResult(BusinessCompletionResult::PERMANENT_FAILURE, 'reconciliation_not_completed', $reconciliationId);
            }
            $key = hash('sha256', 'business-completion-v1|' . $reconciliationId . '|' . $reconciliation->financialResult()->fingerprint());
            $now = current_time('mysql', true);
            $completion = $this->completions->ensure($reconciliationId, $key, $now);
            if (($completion['status'] ?? null) === 'completed') {
                return new BusinessCompletionResult(BusinessCompletionResult::ALREADY_COMPLETED, 'already_completed', $reconciliationId, (int) $completion['id'], (int) $completion['payment_id']);
            }
            if (in_array($completion['status'] ?? null, ['manual_review', 'permanent_failure'], true)) {
                return new BusinessCompletionResult((string) $completion['status'], (string) $completion['last_result_code'], $reconciliationId, (int) $completion['id']);
            }
            $expires = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') + $leaseSeconds);
            $claim = $this->completions->acquire((int) $completion['id'], $workerId, $now, $expires);
            if ($claim === null) {
                return new BusinessCompletionResult(BusinessCompletionResult::RETRYABLE, 'claim_unavailable', $reconciliationId, (int) $completion['id']);
            }
            return $this->materialize($reconciliation, $claim, $workerId, $now);
        } catch (BusinessCompletionFailure $failure) {
            return new BusinessCompletionResult($failure->outcome, $failure->reason, $reconciliationId);
        } catch (Throwable $exception) {
            error_log('[VeciAhorra] Business completion fallo: ' . get_class($exception));
            return new BusinessCompletionResult(BusinessCompletionResult::RETRYABLE, 'unexpected_failure', $reconciliationId);
        }
    }

    private function materialize(PaymentReconciliation $reconciliation, array $claim, string $workerId, string $now): BusinessCompletionResult
    {
        $completionId = (int) $claim['id'];
        $version = (int) $claim['lease_version'];
        try {
            $paymentId = $this->completions->transaction(function () use ($reconciliation, $completionId, $version, $workerId, $now): int {
                if ($this->completions->lock($completionId, $workerId, $version) === null) {
                    throw new BusinessCompletionFailure('lease_lost', BusinessCompletionResult::LEASE_LOST);
                }
                $reconciliationRow = $this->completions->lockReconciliation($reconciliation->id());
                if (($reconciliationRow['reconciliation_status'] ?? null) !== PaymentReconciliation::STATUS_COMPLETED) {
                    throw new BusinessCompletionFailure('reconciliation_changed', BusinessCompletionResult::MANUAL_REVIEW);
                }
                $origin = $reconciliation->origin();
                if ($origin->origin() !== DurablePaymentOrigin::ORIGIN_VECIAHORRA) {
                    throw new BusinessCompletionFailure('unsupported_origin', BusinessCompletionResult::PERMANENT_FAILURE);
                }
                $checkout = $this->checkouts->findByPublicIdForUpdate($origin->originResourceId());
                $session = $this->sessions->findByPublicIdForUpdate($origin->paymentAttemptId());
                if ($checkout === null || $session === null) {
                    throw new BusinessCompletionFailure('checkout_or_session_missing', BusinessCompletionResult::PERMANENT_FAILURE);
                }
                $this->validateAuthorities($reconciliation, $checkout, $session);
                $fulfillmentMethod = $checkout['fulfillment_method'] ?? null;
                if (! is_string($fulfillmentMethod) || ! in_array($fulfillmentMethod, ['pickup', 'delivery'], true)) {
                    throw new BusinessCompletionFailure('legacy_fulfillment_missing', BusinessCompletionResult::MANUAL_REVIEW);
                }
                $orderIds = $this->checkoutOrders->findOrderIds((int) $checkout['id'], true);
                if ($orderIds === []) {
                    throw new BusinessCompletionFailure('empty_order_set', BusinessCompletionResult::PERMANENT_FAILURE);
                }
                sort($orderIds, SORT_NUMERIC);
                $orders = $this->orders->findManyForUpdate($orderIds);
                $this->validateOrders($checkout, $orders, $orderIds);
                $payment = $this->payments->findByReconciliationIdForUpdate($reconciliation->id());
                if ($payment === null) {
                    $paymentId = $this->payments->create($this->paymentData($reconciliation, $checkout, $session, $now));
                    $payment = $this->payments->find($paymentId);
                } else {
                    $paymentId = (int) $payment['id'];
                    $this->validatePayment($payment, $reconciliation, $checkout, $session);
                }
                if (($payment['status'] ?? null) === 'pending') {
                    $this->payments->updateStatus($paymentId, 'pending', 'paid', $now, $now);
                } elseif (($payment['status'] ?? null) !== 'paid') {
                    throw new BusinessCompletionFailure('payment_state_conflict', BusinessCompletionResult::MANUAL_REVIEW);
                }
                $this->sessions->linkPayment((int) $session['id'], $paymentId);
                foreach ($orderIds as $orderId) {
                    $this->payments->attachOrderIdempotently($paymentId, $orderId, $now);
                }
                $this->completions->sealFulfillmentSnapshot(
                    $completionId,
                    $fulfillmentMethod,
                    $orderIds,
                    $now
                );
                $reserved = array_map('intval', array_column(array_filter($orders, static fn (array $order): bool => ($order['status'] ?? null) === 'reserved'), 'id'));
                if ($reserved !== []) {
                    $this->orders->markPaid($reserved, $now);
                }
                $completedAt = current_time('mysql', true);
                if (! $this->completions->complete($completionId, $workerId, $version, $paymentId, $completedAt)) {
                    throw new BusinessCompletionFailure('lease_lost', BusinessCompletionResult::LEASE_LOST);
                }
                return $paymentId;
            });
            return new BusinessCompletionResult(BusinessCompletionResult::COMPLETED, 'completed', $reconciliation->id(), $completionId, $paymentId);
        } catch (BusinessCompletionFailure $failure) {
            try {
                $status = $failure->outcome === BusinessCompletionResult::MANUAL_REVIEW ? 'manual_review' : ($failure->outcome === BusinessCompletionResult::PERMANENT_FAILURE ? 'permanent_failure' : 'retryable');
                $this->completions->fail($completionId, $workerId, $version, $status, $failure->reason, current_time('mysql', true));
            } catch (Throwable) {
            }
            return new BusinessCompletionResult($failure->outcome, $failure->reason, $reconciliation->id(), $completionId);
        } catch (Throwable $exception) {
            try {
                $this->completions->fail($completionId, $workerId, $version, 'retryable', 'unexpected_failure', current_time('mysql', true));
            } catch (Throwable) {
            }
            error_log('[VeciAhorra] Business completion transaccional fallo: ' . get_class($exception));
            return new BusinessCompletionResult(BusinessCompletionResult::RETRYABLE, 'unexpected_failure', $reconciliation->id(), $completionId);
        }
    }

    private function validateAuthorities(PaymentReconciliation $r, array $checkout, array $session): void
    {
        $financial = $r->financialResult();
        $origin = $r->origin();
        if ($financial->financialStatus() !== 'approved' || $origin->currency() !== 'CLP' || ($checkout['currency'] ?? null) !== 'CLP' || ($session['currency'] ?? null) !== 'CLP') {
            throw new BusinessCompletionFailure('currency_or_approval_mismatch', BusinessCompletionResult::MANUAL_REVIEW);
        }
        if ((int) $session['checkout_id'] !== (int) $checkout['id'] || ($session['provider'] ?? null) !== 'webpay_plus' || ! in_array($session['status'] ?? null, [PaymentSession::STATUS_READY, PaymentSession::STATUS_CONFIRMED], true)) {
            throw new BusinessCompletionFailure('authority_relationship_mismatch', BusinessCompletionResult::MANUAL_REVIEW);
        }
        $amount = $this->clp((string) $checkout['total_amount']);
        if ($amount !== $origin->amountClp() || $amount !== $financial->components()->amountClp() || $amount !== $this->clp((string) $session['amount'])) {
            throw new BusinessCompletionFailure('amount_mismatch', BusinessCompletionResult::MANUAL_REVIEW);
        }
        if (! in_array($checkout['status'] ?? null, [Checkout::STATUS_PAYMENT_STARTED], true)) {
            throw new BusinessCompletionFailure('checkout_state_conflict', BusinessCompletionResult::MANUAL_REVIEW);
        }
    }

    private function validateOrders(array $checkout, array $orders, array $expectedIds): void
    {
        if (array_map('intval', array_column($orders, 'id')) !== $expectedIds) {
            throw new BusinessCompletionFailure('order_set_mismatch', BusinessCompletionResult::MANUAL_REVIEW);
        }
        $total = 0;
        foreach ($orders as $order) {
            if (! in_array($order['status'] ?? null, ['reserved', 'paid'], true)) {
                throw new BusinessCompletionFailure('order_state_conflict', BusinessCompletionResult::MANUAL_REVIEW);
            }
            if (($checkout['owner_type'] ?? null) === 'user' && (int) $order['customer_id'] !== (int) $checkout['user_id']) {
                throw new BusinessCompletionFailure('checkout_owner_mismatch', BusinessCompletionResult::MANUAL_REVIEW);
            }
            if (($checkout['owner_type'] ?? null) === 'session' && (int) $order['customer_id'] !== 0) {
                throw new BusinessCompletionFailure('checkout_owner_mismatch', BusinessCompletionResult::MANUAL_REVIEW);
            }
            $total += $this->clp((string) $order['total']);
        }
        if ($total !== $this->clp((string) $checkout['total_amount'])) {
            throw new BusinessCompletionFailure('orders_amount_mismatch', BusinessCompletionResult::MANUAL_REVIEW);
        }
    }

    private function paymentData(PaymentReconciliation $r, array $checkout, array $session, string $now): array
    {
        $fingerprint = $r->financialResult()->fingerprint();
        return [
            'payment_reference' => 'bcp_' . substr(hash('sha256', 'payment|' . $r->id()), 0, 52),
            'checkout_id' => (int) $checkout['id'], 'payment_session_id' => (int) $session['id'],
            'reconciliation_id' => $r->id(), 'payment_attempt_id' => $r->origin()->paymentAttemptId(),
            'financial_fingerprint' => $fingerprint,
            'idempotency_key' => hash('sha256', 'business-payment-v1|' . $r->id() . '|' . $fingerprint),
            'customer_id' => ($checkout['owner_type'] ?? null) === 'user' ? (int) $checkout['user_id'] : 0,
            'amount' => (string) $checkout['total_amount'], 'currency' => 'CLP', 'status' => 'pending',
            'provider' => 'webpay_plus', 'provider_reference' => $r->financialResult()->safeFinancialReference(),
            'expires_at' => null, 'paid_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function validatePayment(array $payment, PaymentReconciliation $r, array $checkout, array $session): void
    {
        $expected = $this->paymentData($r, $checkout, $session, (string) $payment['created_at']);
        foreach (['checkout_id', 'payment_session_id', 'reconciliation_id', 'payment_attempt_id', 'financial_fingerprint', 'idempotency_key', 'customer_id', 'amount', 'currency', 'provider', 'provider_reference'] as $field) {
            if ((string) ($payment[$field] ?? '') !== (string) $expected[$field]) {
                throw new BusinessCompletionFailure('payment_identity_conflict', BusinessCompletionResult::MANUAL_REVIEW);
            }
        }
    }

    private function clp(string $amount): int
    {
        if (preg_match('/^([1-9]\d*)\.00$/D', $amount, $matches) !== 1) {
            throw new BusinessCompletionFailure('invalid_clp_amount', BusinessCompletionResult::MANUAL_REVIEW);
        }
        return (int) $matches[1];
    }
}
