<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Service;

use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Checkout\Models\Checkout;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;
use VeciAhorra\Modules\Payments\Gateway\WebpayPaymentGateway;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Repository\PublicPaymentStatusRepository;

final class PublicPaymentStatusService
{
    public function __construct(
        private readonly CheckoutRepository $checkouts = new CheckoutRepository(),
        private readonly PublicPaymentStatusRepository $statuses = new PublicPaymentStatusRepository(),
        private readonly IdempotencyService $identity = new IdempotencyService()
    ) {}

    public function project(string $checkoutPublicId, array $ownerInput): array
    {
        if (! Checkout::validPublicId($checkoutPublicId)) {
            throw new RecordNotFoundException('El Checkout no esta disponible.');
        }
        $checkout = $this->checkouts->findOwnedByPublicId(
            $checkoutPublicId,
            $this->identity->owner($ownerInput)
        );
        if ($checkout === null) {
            throw new RecordNotFoundException('El Checkout no esta disponible.');
        }
        $attempts = array_values(array_filter(
            $this->statuses->findAttempts((int) $checkout['id']),
            static fn (array $row): bool => ($row['origin'] ?? null) === null
                || $row['origin'] === DurablePaymentOrigin::ORIGIN_VECIAHORRA
        ));
        $selected = $this->select($attempts);
        $projection = $selected === null
            ? (in_array($checkout['status'], [Checkout::STATUS_EXPIRED, Checkout::STATUS_CANCELLED], true)
                || (string) $checkout['expires_at'] <= current_time('mysql')
                    ? $this->state('payment_expired')
                    : $this->state('pending'))
            : $this->projectAttempt($selected);

        return [
            'checkout_id' => $checkoutPublicId,
            'payment_session_id' => $selected['public_id'] ?? null,
            ...$projection,
            'updated_at' => $selected === null
                ? (string) $checkout['updated_at']
                : $this->updatedAt($selected),
        ];
    }

    private function select(array $attempts): ?array
    {
        if ($attempts === []) {
            return null;
        }
        $best = $attempts[0];
        $bestRank = $this->rank($best);
        foreach ($attempts as $attempt) {
            $rank = $this->rank($attempt);
            if ($rank > $bestRank) {
                $best = $attempt;
                $bestRank = $rank;
            }
        }
        return $best;
    }

    private function rank(array $row): int
    {
        $fulfillment = $row['fulfillment_status'] ?? null;
        if ($fulfillment === 'completed') { return 1000; }
        if (in_array($fulfillment, ['manual_review', 'permanent_failure', 'failed'], true)) { return 950; }
        if ($fulfillment !== null) { return 800; }
        if (($row['delivery_status'] ?? null) !== null) { return 750; }
        if (($row['business_status'] ?? null) !== null) { return 700; }
        if (($row['reconciliation_status'] ?? null) === 'completed') { return 650; }
        if (in_array($row['reconciliation_status'] ?? null,
            ['manual_review', 'permanent_failure'], true)) { return 600; }
        if (in_array($row['reconciliation_status'] ?? null,
            ['processing', 'retryable', 'pending'], true)) { return 500; }
        if (in_array($row['return_result_status'] ?? null, ['approved'], true)
            || ($row['return_processing_status'] ?? null) === 'processing') { return 450; }
        if (($row['return_processing_status'] ?? null) === 'ambiguous'
            || ($row['session_status'] ?? null) === 'create_ambiguous') { return 400; }
        return 100;
    }

    private function projectAttempt(array $row): array
    {
        $fulfillment = $row['fulfillment_status'] ?? null;
        if ($fulfillment === 'completed') { return $this->state('completed'); }
        if ($fulfillment === 'manual_review') { return $this->state('manual_review'); }
        if (in_array($fulfillment, ['permanent_failure', 'failed'], true)) { return $this->state('failed'); }

        foreach (['delivery_status', 'business_status'] as $field) {
            $value = $row[$field] ?? null;
            if ($value === 'manual_review') { return $this->state('manual_review'); }
            if (in_array($value, ['permanent_failure', 'failed'], true)) { return $this->state('failed'); }
            if ($value !== null) { return $this->state('payment_approved_processing'); }
        }

        $reconciliation = $row['reconciliation_status'] ?? null;
        if ($reconciliation === 'completed') { return $this->state('payment_approved_processing'); }
        if ($reconciliation === 'manual_review') { return $this->state('manual_review'); }
        if ($reconciliation === 'permanent_failure') { return $this->state('failed'); }
        if (in_array($reconciliation, ['pending', 'processing', 'retryable'], true)) {
            return $this->state('payment_verifying');
        }

        $returnStatus = $row['return_result_status'] ?? null;
        $returnProcessing = $row['return_processing_status'] ?? null;
        if ($returnProcessing === 'ambiguous' || $returnStatus === 'manual_review') {
            return $this->state('manual_review');
        }
        if ($returnStatus === 'approved' || $returnProcessing === 'processing') {
            return $this->state('payment_verifying');
        }
        if (in_array($returnStatus, ['rejected', 'aborted'], true)) {
            return $this->state('payment_rejected');
        }
        if ($returnStatus === 'inconsistent') { return $this->state('manual_review'); }

        if ((string) ($row['session_expires_at'] ?? '') <= current_time('mysql')) {
            return $this->state('payment_expired');
        }

        return match ($row['session_status'] ?? 'pending') {
            'ready' => $this->ready($row),
            'create_ambiguous' => $this->state('payment_verifying'),
            'create_failed' => $this->state('failed'),
            'expired', 'cancelled' => $this->state('payment_expired'),
            'create_retryable' => $this->state('payment_expired'),
            default => $this->state('pending'),
        };
    }

    private function ready(array $row): array
    {
        $now = current_time('mysql');
        $url = $row['redirect_url'] ?? null;
        if ((string) $row['session_expires_at'] <= $now
            || ! WebpayPaymentGateway::isAllowedPaymentUrl(
                (string) ($row['environment'] ?? ''), $url
            )) {
            return $this->state('payment_expired');
        }
        return [...$this->state('redirect_ready'), 'redirect_url' => $url];
    }

    private function state(string $status): array
    {
        $definitions = [
            'pending' => [false, 2500, 'Estamos preparando el pago.', 'wait'],
            'redirect_ready' => [false, null, 'Tu sesion de pago esta lista.', 'redirect_to_webpay'],
            'payment_verifying' => [false, 3000, 'Estamos verificando el resultado del pago.', 'wait'],
            'payment_approved_processing' => [false, 3000, 'Tu pago fue aprobado y estamos completando tu compra.', 'wait'],
            'completed' => [true, null, 'Tu compra fue completada correctamente.', 'view_order'],
            'payment_rejected' => [true, null, 'El pago fue rechazado. Puedes iniciar un nuevo intento.', 'retry_payment'],
            'payment_expired' => [true, null, 'La sesion de pago vencio. Puedes iniciar un nuevo intento.', 'retry_payment'],
            'manual_review' => [true, null, 'Estamos revisando el pago. No intentes pagar nuevamente.', 'contact_support'],
            'failed' => [true, null, 'No pudimos completar la operacion. No realices otro pago hasta revisar el estado.', 'contact_support'],
        ];
        [$terminal, $poll, $message, $action] = $definitions[$status];
        return [
            'payment_status' => $status,
            'terminal' => $terminal,
            'poll_after_ms' => $poll,
            'message' => $message,
            'next_action' => $action,
            'redirect_url' => null,
        ];
    }

    private function updatedAt(array $row): string
    {
        $values = array_filter([
            $row['session_updated_at'] ?? null, $row['return_updated_at'] ?? null,
            $row['reconciliation_updated_at'] ?? null, $row['business_updated_at'] ?? null,
            $row['delivery_updated_at'] ?? null, $row['fulfillment_updated_at'] ?? null,
        ], 'is_string');
        rsort($values, SORT_STRING);
        return $values[0] ?? current_time('mysql');
    }
}
