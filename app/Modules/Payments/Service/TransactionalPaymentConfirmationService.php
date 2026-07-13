<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Service;

use Throwable;
use VeciAhorra\Modules\Checkout\Repository\CheckoutOrderRepository;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;
use VeciAhorra\Modules\Payments\Contracts\OrderPaymentConfirmationInterface;
use VeciAhorra\Modules\Payments\Exceptions\AmbiguousPaymentCommit;
use VeciAhorra\Modules\Payments\Exceptions\PaymentConfirmationFailure;
use VeciAhorra\Modules\Payments\Gateway\WebpayTransactionReference;
use VeciAhorra\Modules\Payments\Models\NormalizedFinancialApproval;
use VeciAhorra\Modules\Payments\Models\PaymentConfirmationAudit;
use VeciAhorra\Modules\Payments\Models\PaymentSession;
use VeciAhorra\Modules\Payments\Models\TransactionalPaymentConfirmationResult;
use VeciAhorra\Modules\Payments\Repository\PaymentConfirmationAuditRepository;
use VeciAhorra\Modules\Payments\Repository\PaymentRepository;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;
use VeciAhorra\Modules\Payments\Repository\WebpayReturnRepository;
use VeciAhorra\Modules\Payments\Support\PaymentConfirmationFingerprint;
use VeciAhorra\Modules\Reservations\Repository\ReservationRepository;

final class TransactionalPaymentConfirmationService
{
    private const MAX_DATABASE_ATTEMPTS = 2;

    public function __construct(
        private WebpayReturnRepository $returns,
        private PaymentSessionRepository $sessions,
        private PaymentRepository $payments,
        private CheckoutRepository $checkouts,
        private CheckoutOrderRepository $checkoutOrders,
        private OrderPaymentConfirmationInterface $orders,
        private ReservationRepository $reservations,
        private PaymentConfirmationAuditRepository $audits,
        private PaymentConfirmationTransaction $transaction
    ) {
    }

    public function confirm(
        NormalizedFinancialApproval $financial
    ): TransactionalPaymentConfirmationResult {
        if (! $financial->isApproved()) {
            return TransactionalPaymentConfirmationResult::failure(
                'financial_not_approved',
                $financial->correlationId,
                false,
                'info'
            );
        }

        $return = $this->returns->find($financial->tokenHash);

        if (
            $return === null
            || ($return['flow'] ?? null) !== 'commit'
            || ($return['processing_status'] ?? null) !== 'completed'
            || ($return['result_status'] ?? null) !== 'approved'
            || (int) ($return['payment_session_id'] ?? 0) <= 0
        ) {
            return TransactionalPaymentConfirmationResult::failure(
                'session_not_found',
                $financial->correlationId
            );
        }

        $sessionId = (int) $return['payment_session_id'];

        for ($attempt = 1; $attempt <= self::MAX_DATABASE_ATTEMPTS; $attempt++) {
            try {
                return $this->transaction->run(
                    fn (): TransactionalPaymentConfirmationResult =>
                        $this->confirmLocked($financial, $sessionId, $attempt)
                );
            } catch (AmbiguousPaymentCommit) {
                if (! $this->transaction->resetConnection()) {
                    return TransactionalPaymentConfirmationResult::failure(
                        'commit_ambiguous',
                        $financial->correlationId,
                        false,
                        'critical',
                        $sessionId
                    );
                }

                return $this->recover($financial, $sessionId);
            } catch (PaymentConfirmationFailure $failure) {
                if (
                    $failure->retryable
                    && $attempt < self::MAX_DATABASE_ATTEMPTS
                ) {
                    usleep(25000 * $attempt);

                    continue;
                }

                if (! $failure->retryable) {
                    $this->recordFunctionalFailure(
                        $financial,
                        $sessionId,
                        $failure,
                        $attempt
                    );
                }

                return TransactionalPaymentConfirmationResult::failure(
                    $failure->resultCode,
                    $financial->correlationId,
                    $failure->retryable,
                    $failure->severity,
                    $sessionId
                );
            } catch (Throwable) {
                return TransactionalPaymentConfirmationResult::failure(
                    'unexpected_error',
                    $financial->correlationId,
                    false,
                    'critical',
                    $sessionId
                );
            }
        }

        return TransactionalPaymentConfirmationResult::failure(
            'transient_database_error',
            $financial->correlationId,
            true,
            'warning',
            $sessionId
        );
    }

    private function confirmLocked(
        NormalizedFinancialApproval $financial,
        int $sessionId,
        int $attempt
    ): TransactionalPaymentConfirmationResult {
        $session = $this->sessions->findForUpdate($sessionId)
            ?? throw new PaymentConfirmationFailure('session_not_found');
        $paymentId = (int) ($session['payment_id'] ?? 0);

        if ($paymentId <= 0) {
            throw new PaymentConfirmationFailure('relationship_mismatch');
        }

        $payment = $this->payments->findByPaymentSessionIdForUpdate($sessionId)
            ?? throw new PaymentConfirmationFailure('payment_not_found');
        $checkoutId = (int) ($session['checkout_id'] ?? 0);
        $checkout = $this->checkouts->find($checkoutId)
            ?? throw new PaymentConfirmationFailure('checkout_not_found');
        $checkoutOrderIds = $this->checkoutOrders->findOrderIds(
            $checkoutId,
            true
        );
        $paymentOrderIds = $this->payments->findOrderIdsForUpdate($paymentId);

        if (
            $checkoutOrderIds === []
            || $checkoutOrderIds !== $paymentOrderIds
        ) {
            throw new PaymentConfirmationFailure('order_set_mismatch');
        }

        $orders = $this->orders->lockForPayment($checkoutOrderIds);

        if (count($orders) !== count($checkoutOrderIds)) {
            throw new PaymentConfirmationFailure('orders_not_found');
        }

        $reservations = $this->reservations->findByOrderIdsForUpdate(
            $checkoutOrderIds
        );
        $this->validateRelationships(
            $financial,
            $session,
            $payment,
            $checkout,
            $orders
        );
        $fingerprint = $this->fingerprint(
            $financial,
            $session,
            $payment,
            $checkout,
            $checkoutOrderIds
        );
        $terminal = $this->terminalResult(
            $financial,
            $session,
            $payment,
            $checkout,
            $orders,
            $reservations,
            $fingerprint,
            $attempt
        );

        if ($terminal !== null) {
            return $terminal;
        }

        $this->validateInitialStates($session, $payment, $orders);
        $this->validateReservations(
            $reservations,
            $checkoutOrderIds,
            current_time('mysql')
        );
        $now = current_time('mysql');
        $this->audits->insert($this->audit(
            PaymentConfirmationAudit::EVENT_STARTED,
            'confirmation_started',
            'info',
            $financial,
            $sessionId,
            $paymentId,
            $checkoutId,
            $checkoutOrderIds,
            $fingerprint,
            'ready_pending_reserved',
            'ready_pending_reserved',
            $attempt,
            $now
        ));
        $this->sessions->storeConfirmationEvidence(
            $sessionId,
            $paymentId,
            $fingerprint,
            PaymentConfirmationFingerprint::VERSION,
            $financial->safeFinancialReference,
            $now
        );
        $this->payments->updateStatus(
            $paymentId,
            'pending',
            'paid',
            $now,
            $now
        );
        $updatedOrders = $this->orders->confirmPaid(
            $checkoutOrderIds,
            $now
        );

        if ($updatedOrders !== count($checkoutOrderIds)) {
            throw new PaymentConfirmationFailure('partial_inconsistency');
        }

        $reservationIds = array_map(
            static fn (array $reservation): int => (int) $reservation['id'],
            $reservations
        );
        $consumed = $this->reservations->markConsumed($reservationIds, $now);

        if ($consumed !== count($reservationIds)) {
            throw new PaymentConfirmationFailure('partial_inconsistency');
        }

        $this->audits->insert($this->audit(
            PaymentConfirmationAudit::EVENT_SUCCEEDED,
            'confirmed',
            'info',
            $financial,
            $sessionId,
            $paymentId,
            $checkoutId,
            $checkoutOrderIds,
            $fingerprint,
            'ready_pending_reserved',
            'confirmed_paid_paid',
            $attempt,
            $now
        ));

        return TransactionalPaymentConfirmationResult::confirmed(
            $financial->correlationId,
            $sessionId,
            $paymentId,
            $checkoutId,
            $fingerprint
        );
    }

    private function validateRelationships(
        NormalizedFinancialApproval $financial,
        array $session,
        array $payment,
        array $checkout,
        array $orders
    ): void {
        if ((int) $payment['id'] !== (int) $session['payment_id']) {
            throw new PaymentConfirmationFailure('relationship_mismatch');
        }

        if (($checkout['status'] ?? null) !== 'payment_started') {
            throw new PaymentConfirmationFailure('invalid_state');
        }

        if (
            ($session['provider'] ?? null) !== $financial->provider
            || ($payment['provider'] ?? null) !== $financial->provider
        ) {
            throw new PaymentConfirmationFailure('provider_mismatch');
        }

        if (
            ! is_string($session['provider_session_id'] ?? null)
            || ! hash_equals(
                $financial->tokenHash,
                hash('sha256', (string) $session['provider_session_id'])
            )
        ) {
            throw new PaymentConfirmationFailure('relationship_mismatch');
        }


        if (
            $financial->safeFinancialReference
            !== 'sha256:' . substr($financial->tokenHash, 0, 12)
        ) {
            throw new PaymentConfirmationFailure('relationship_mismatch');
        }

        $amounts = [
            $this->clp((string) $session['amount']),
            $this->clp((string) $payment['amount']),
            $this->clp((string) $checkout['total_amount']),
            $this->ordersAmount($orders),
        ];

        if (count(array_unique($amounts, SORT_REGULAR)) !== 1
            || $amounts[0] !== $financial->amount) {
            throw new PaymentConfirmationFailure('amount_mismatch');
        }

        if (
            ($session['currency'] ?? null) !== 'CLP'
            || ($payment['currency'] ?? null) !== 'CLP'
            || ($checkout['currency'] ?? null) !== 'CLP'
            || $financial->currency !== 'CLP'
        ) {
            throw new PaymentConfirmationFailure('currency_mismatch');
        }

        $checkoutPublicId = (string) $checkout['public_id'];

        if (! hash_equals(
            WebpayTransactionReference::buyOrder(
                $checkoutPublicId,
                (string) $session['idempotency_key']
            ),
            $financial->buyOrder
        )) {
            throw new PaymentConfirmationFailure('buy_order_mismatch');
        }

        if (! hash_equals(
            WebpayTransactionReference::sessionId($checkoutPublicId),
            $financial->financialSessionId
        )) {
            throw new PaymentConfirmationFailure(
                'session_identifier_mismatch'
            );
        }
    }

    private function terminalResult(
        NormalizedFinancialApproval $financial,
        array $session,
        array $payment,
        array $checkout,
        array $orders,
        array $reservations,
        string $fingerprint,
        int $attempt
    ): ?TransactionalPaymentConfirmationResult {
        $sessionConfirmed = ($session['status'] ?? null) === 'confirmed';
        $paymentPaid = ($payment['status'] ?? null) === 'paid';
        $paidOrderCount = count(array_filter(
            $orders,
            static fn (array $order): bool => ($order['status'] ?? null) === 'paid'
        ));
        $ordersPaid = $orders !== [] && $paidOrderCount === count($orders);
        $anyTerminal = $sessionConfirmed || $paymentPaid || $paidOrderCount > 0;

        if (! $anyTerminal) {
            if (isset($session['confirmation_fingerprint'])) {
                if (! hash_equals(
                    (string) $session['confirmation_fingerprint'],
                    $fingerprint
                )) {
                    throw new PaymentConfirmationFailure(
                        'idempotency_conflict',
                        false,
                        'critical'
                    );
                }

                throw new PaymentConfirmationFailure(
                    'partial_inconsistency',
                    false,
                    'critical'
                );
            }

            return null;
        }

        if (! ($sessionConfirmed && $paymentPaid && $ordersPaid)) {
            throw new PaymentConfirmationFailure(
                'partial_inconsistency',
                false,
                'critical'
            );
        }


        if (
            ! $this->reservations->matchOrderItems(
                $reservations,
                array_map('intval', array_column($orders, 'id'))
            )
            || count(array_filter(
                $reservations,
                static fn (array $reservation): bool =>
                    ($reservation['status'] ?? null) === 'consumed'
            )) !== count($reservations)
        ) {
            throw new PaymentConfirmationFailure(
                'partial_inconsistency',
                false,
                'critical'
            );
        }

        if (
            ! is_string($session['confirmation_fingerprint'] ?? null)
            || ! PaymentConfirmationFingerprint::matches(
                (string) $session['confirmation_fingerprint'],
                $fingerprint
            )
            || ($session['safe_financial_reference'] ?? null)
                !== $financial->safeFinancialReference
        ) {
            throw new PaymentConfirmationFailure(
                'idempotency_conflict',
                false,
                'critical'
            );
        }

        $sessionId = (int) $session['id'];

        if ($this->audits->countEvent(
            $sessionId,
            PaymentConfirmationAudit::EVENT_SUCCEEDED
        ) !== 1) {
            throw new PaymentConfirmationFailure(
                'partial_inconsistency',
                false,
                'critical'
            );
        }

        $this->audits->insert($this->audit(
            PaymentConfirmationAudit::EVENT_IDEMPOTENT,
            'already_confirmed',
            'info',
            $financial,
            $sessionId,
            (int) $payment['id'],
            (int) $checkout['id'],
            array_map('intval', array_column($orders, 'id')),
            $fingerprint,
            'confirmed_paid_paid',
            'confirmed_paid_paid',
            $attempt,
            current_time('mysql')
        ));

        return TransactionalPaymentConfirmationResult::alreadyConfirmed(
            $financial->correlationId,
            $sessionId,
            (int) $payment['id'],
            (int) $checkout['id'],
            $fingerprint
        );
    }

    private function validateInitialStates(
        array $session,
        array $payment,
        array $orders
    ): void {
        if (
            ($session['status'] ?? null) !== PaymentSession::STATUS_READY
            || ($payment['status'] ?? null) !== 'pending'
            || count(array_filter(
                $orders,
                static fn (array $order): bool =>
                    ($order['status'] ?? null) === 'reserved'
            )) !== count($orders)
        ) {
            throw new PaymentConfirmationFailure('invalid_state');
        }
    }

    private function validateReservations(
        array $reservations,
        array $orderIds,
        string $now
    ): void {
        if (! $this->reservations->matchOrderItems($reservations, $orderIds)) {
            throw new PaymentConfirmationFailure(
                'reservation_expired',
                false,
                'high'
            );
        }

        foreach ($reservations as $reservation) {
            if (
                ($reservation['status'] ?? null) !== 'active'
                || (string) ($reservation['expires_at'] ?? '') <= $now
            ) {
                throw new PaymentConfirmationFailure(
                    'reservation_expired',
                    false,
                    'high'
                );
            }
        }
    }

    private function fingerprint(
        NormalizedFinancialApproval $financial,
        array $session,
        array $payment,
        array $checkout,
        array $orderIds
    ): string {
        return PaymentConfirmationFingerprint::make([
            'provider' => $financial->provider,
            'payment_session_id' => (int) $session['id'],
            'payment_id' => (int) $payment['id'],
            'checkout_id' => (int) $checkout['id'],
            'order_ids' => $orderIds,
            'amount' => $financial->amount,
            'currency' => $financial->currency,
            'buy_order' => $financial->buyOrder,
            'financial_session_id' => $financial->financialSessionId,
            'safe_financial_reference' =>
                $financial->safeFinancialReference,
            'transaction_date' => $financial->transactionDate,
        ]);
    }

    private function audit(
        string $event,
        string $resultCode,
        string $severity,
        NormalizedFinancialApproval $financial,
        int $sessionId,
        int $paymentId,
        int $checkoutId,
        array $orderIds,
        string $fingerprint,
        string $previousState,
        string $resultingState,
        int $attempt,
        string $createdAt
    ): PaymentConfirmationAudit {
        return new PaymentConfirmationAudit(
            $financial->correlationId,
            $event,
            $sessionId,
            $paymentId,
            $checkoutId,
            $fingerprint,
            PaymentConfirmationFingerprint::VERSION,
            $financial->provider,
            number_format($financial->amount, 2, '.', ''),
            $financial->currency,
            $previousState,
            $resultingState,
            $resultCode,
            $severity,
            $attempt,
            $financial->safeFinancialReference,
            $orderIds,
            [
                'origin' => $financial->origin,
                'lock_order' => 'session:payment:orders:reservations',
            ],
            $createdAt
        );
    }

    private function clp(string $amount): int
    {
        if (preg_match('/^([1-9]\d*)\.00$/D', $amount, $matches) !== 1) {
            throw new PaymentConfirmationFailure('amount_mismatch');
        }

        $value = filter_var($matches[1], FILTER_VALIDATE_INT);

        if ($value === false || $value <= 0) {
            throw new PaymentConfirmationFailure('amount_mismatch');
        }

        return $value;
    }

    private function ordersAmount(array $orders): int
    {
        $total = 0;

        foreach ($orders as $order) {
            $total += $this->clp((string) ($order['total'] ?? ''));
        }

        return $total;
    }

    private function recover(
        NormalizedFinancialApproval $financial,
        int $sessionId
    ): TransactionalPaymentConfirmationResult {
        try {
            $session = $this->sessions->find($sessionId);

            if ($session === null || (int) ($session['payment_id'] ?? 0) <= 0) {
                return TransactionalPaymentConfirmationResult::failure(
                    'commit_ambiguous',
                    $financial->correlationId,
                    false,
                    'critical',
                    $sessionId
                );
            }

            $payment = $this->payments->findByPaymentSessionId($sessionId);
            $checkout = $this->checkouts->find((int) $session['checkout_id']);

            if ($payment === null || $checkout === null) {
                return TransactionalPaymentConfirmationResult::failure(
                    'commit_ambiguous',
                    $financial->correlationId,
                    false,
                    'critical',
                    $sessionId
                );
            }

            $orderIds = $this->checkoutOrders->findOrderIds(
                (int) $checkout['id']
            );
            $orders = $this->orders->readForRecovery($orderIds);
            $reservations = $this->reservations->findByOrderIds($orderIds);
            $fingerprint = $this->fingerprint(
                $financial,
                $session,
                $payment,
                $checkout,
                $orderIds
            );
            $allConfirmed = ($session['status'] ?? null) === 'confirmed'
                && ($payment['status'] ?? null) === 'paid'
                && $orders !== []
                && count(array_filter(
                    $orders,
                    static fn (array $order): bool =>
                        ($order['status'] ?? null) === 'paid'
                )) === count($orderIds)
                && is_string($session['confirmation_fingerprint'] ?? null)
                && PaymentConfirmationFingerprint::matches(
                    (string) $session['confirmation_fingerprint'],
                    $fingerprint
                )
                && $this->audits->countEvent(
                    $sessionId,
                    PaymentConfirmationAudit::EVENT_SUCCEEDED
                ) === 1
                && $this->reservations->matchOrderItems(
                    $reservations,
                    $orderIds
                )
                && count(array_filter(
                    $reservations,
                    static fn (array $reservation): bool =>
                        ($reservation['status'] ?? null) === 'consumed'
                )) === count($reservations);

            if ($allConfirmed) {
                return TransactionalPaymentConfirmationResult::alreadyConfirmed(
                    $financial->correlationId,
                    $sessionId,
                    (int) $payment['id'],
                    (int) $checkout['id'],
                    $fingerprint
                );
            }

            $allPrevious = ($session['status'] ?? null) === 'ready'
                && ($payment['status'] ?? null) === 'pending'
                && $orders !== []
                && count(array_filter(
                    $orders,
                    static fn (array $order): bool =>
                        ($order['status'] ?? null) === 'reserved'
                )) === count($orderIds)
                && ! isset($session['confirmation_fingerprint'])
                && $this->audits->countEvent(
                    $sessionId,
                    PaymentConfirmationAudit::EVENT_SUCCEEDED
                ) === 0;

            if ($allPrevious) {
                return TransactionalPaymentConfirmationResult::failure(
                    'not_confirmed',
                    $financial->correlationId,
                    true,
                    'warning',
                    $sessionId,
                    (int) $payment['id'],
                    (int) $checkout['id']
                );
            }

            return TransactionalPaymentConfirmationResult::failure(
                'partial_inconsistency',
                $financial->correlationId,
                false,
                'critical',
                $sessionId,
                (int) $payment['id'],
                (int) $checkout['id']
            );
        } catch (Throwable) {
            return TransactionalPaymentConfirmationResult::failure(
                'commit_ambiguous',
                $financial->correlationId,
                false,
                'critical',
                $sessionId
            );
        }
    }

    private function recordFunctionalFailure(
        NormalizedFinancialApproval $financial,
        int $sessionId,
        PaymentConfirmationFailure $failure,
        int $attempt
    ): void {
        if (! in_array($failure->resultCode, [
            'relationship_mismatch', 'order_set_mismatch', 'amount_mismatch',
            'currency_mismatch', 'buy_order_mismatch',
            'session_identifier_mismatch', 'provider_mismatch',
            'reservation_expired', 'invalid_state', 'idempotency_conflict',
            'partial_inconsistency',
        ], true)) {
            return;
        }

        try {
            $session = $this->sessions->find($sessionId);

            if ($session === null || (int) ($session['payment_id'] ?? 0) <= 0) {
                return;
            }

            $payment = $this->payments->findByPaymentSessionId($sessionId);
            $checkout = $this->checkouts->find((int) $session['checkout_id']);

            if ($payment === null || $checkout === null) {
                return;
            }

            $orderIds = $this->checkoutOrders->findOrderIds(
                (int) $checkout['id']
            );

            if ($orderIds === []) {
                return;
            }

            $fingerprint = $this->fingerprint(
                $financial,
                $session,
                $payment,
                $checkout,
                $orderIds
            );
            $event = match ($failure->resultCode) {
                'reservation_expired' =>
                    PaymentConfirmationAudit::EVENT_RESERVATION_EXPIRED,
                'amount_mismatch', 'currency_mismatch',
                'buy_order_mismatch', 'session_identifier_mismatch',
                'provider_mismatch' =>
                    PaymentConfirmationAudit::EVENT_FINANCIAL_MISMATCH,
                'idempotency_conflict' =>
                    PaymentConfirmationAudit::EVENT_CONFLICT,
                default => PaymentConfirmationAudit::EVENT_STATE_MISMATCH,
            };
            $now = current_time('mysql');
            $audit = $this->audit(
                    $event,
                    $failure->resultCode,
                    $failure->severity,
                    $financial,
                    $sessionId,
                    (int) $payment['id'],
                    (int) $checkout['id'],
                    $orderIds,
                    $fingerprint,
                    (string) $session['status'],
                    (string) $session['status'],
                    $attempt,
                    $now
                );

            if (
                $audit->eventKey() !== null
                && $this->audits->hasEventKey($audit->eventKey())
            ) {
                return;
            }

            $this->transaction->run(
                fn (): int => $this->audits->insert($audit)
            );
        } catch (Throwable) {
            error_log(
                '[VeciAhorra] No fue posible auditar un fallo de confirmacion.'
            );
        }
    }
}
