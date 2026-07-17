<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\CustomerPanel\Service;

use VeciAhorra\Modules\CustomerPanel\DTO\CustomerPurchaseVisibleStatus;

/** Pure, conservative translation from durable evidence to customer language. */
final class CustomerPurchaseStatusResolver
{
    public function resolve(array $context): CustomerPurchaseVisibleStatus
    {
        if (($context['inconsistent'] ?? false) === true) {
            return $this->status('under_review');
        }

        $checkout = (string) ($context['checkout_status'] ?? '');
        $attempt = $context['attempt'] ?? [];
        $reconciliation = $attempt['reconciliation_status'] ?? null;
        $financial = $attempt['financial_status'] ?? null;
        $business = $attempt['business_status'] ?? null;
        $session = $attempt['session_status'] ?? null;
        $deliveryCompletion = $attempt['delivery_completion_status'] ?? null;
        $fulfillmentCompletion = $attempt['fulfillment_completion_status'] ?? null;
        $method = $context['fulfillment_method'] ?? null;
        $payment = $context['payment'] ?? null;
        $deliveries = array_values(array_map(
            static fn (array $delivery): string => (string) ($delivery['status'] ?? ''),
            $context['deliveries'] ?? []
        ));

        if (! in_array($checkout, ['pending', 'payment_started', 'expired', 'cancelled'], true)
            || ($reconciliation !== null && ! in_array($reconciliation, ['pending', 'processing', 'completed', 'retryable', 'permanent_failure', 'manual_review'], true))
            || ($business !== null && ! in_array($business, ['pending', 'processing', 'completed', 'retryable', 'permanent_failure', 'manual_review'], true))
            || ($session !== null && ! in_array($session, ['pending', 'create_processing', 'create_retryable', 'create_ambiguous', 'create_failed', 'ready', 'confirmed', 'expired', 'cancelled'], true))
            || ($financial !== null && ! in_array($financial, ['approved', 'rejected'], true))
            || ($payment !== null && ! in_array($payment['status'] ?? null, ['pending', 'paid'], true))
            || ($deliveryCompletion !== null && ! in_array($deliveryCompletion, ['pending', 'processing', 'completed', 'not_required', 'retryable', 'permanent_failure', 'manual_review'], true))
            || ($fulfillmentCompletion !== null && ! in_array($fulfillmentCompletion, ['pending', 'processing', 'completed', 'retryable', 'permanent_failure', 'manual_review'], true))
            || array_diff($deliveries, ['pending', 'assigned', 'picked_up', 'delivered', 'cancelled']) !== []
        ) {
            return $this->status('under_review');
        }

        if (in_array($reconciliation, ['manual_review'], true)
            || in_array($business, ['manual_review', 'permanent_failure'], true)
            || in_array($attempt['delivery_completion_status'] ?? null, ['manual_review', 'permanent_failure'], true)
            || in_array($attempt['fulfillment_completion_status'] ?? null, ['manual_review', 'permanent_failure'], true)
        ) {
            return $this->status('under_review');
        }
        if ($session === 'create_ambiguous'
            || ($session === 'ready' && $checkout !== 'payment_started')
            || ($financial !== null && $reconciliation === null)
        ) {
            return $this->status('under_review');
        }
        if ($reconciliation === 'permanent_failure') {
            return $financial === 'rejected'
                ? $this->status('payment_rejected')
                : $this->status('under_review');
        }
        if ($checkout === 'cancelled') {
            return $this->status('cancelled');
        }
        if ($deliveries !== []
            && (($payment['status'] ?? null) !== 'paid'
                || $reconciliation !== 'completed'
                || $financial !== 'approved'
                || $business !== 'completed')
        ) {
            return $this->status('under_review');
        }
        if (in_array('cancelled', $deliveries, true)) {
            return count(array_filter($deliveries, static fn (string $s): bool => $s === 'cancelled')) === count($deliveries)
                ? $this->status('cancelled')
                : $this->status('under_review');
        }
        if ($deliveries !== [] && count(array_filter($deliveries, static fn (string $s): bool => $s === 'delivered')) === count($deliveries)) {
            return $this->status('delivered');
        }
        if ($deliveries !== [] && count(array_filter($deliveries, static fn (string $s): bool => in_array($s, ['picked_up', 'delivered'], true))) === count($deliveries)
            && in_array('picked_up', $deliveries, true)
        ) {
            return $this->status('out_for_delivery');
        }
        if ($method === 'delivery'
            && $deliveries !== []
            && count(array_filter($deliveries, static fn (string $s): bool => in_array($s, ['pending', 'assigned'], true))) === count($deliveries)
        ) {
            return $this->status('preparing_delivery');
        }
        if ($deliveries !== []) {
            return $this->status('under_review');
        }
        if ($business === 'completed'
            && $reconciliation === 'completed'
            && $financial === 'approved'
            && ($payment['status'] ?? null) === 'paid'
        ) {
            return $this->status('preparing_order');
        }
        if ($business === 'completed') {
            return $this->status('under_review');
        }
        if ($reconciliation === 'completed' && ($payment['status'] ?? null) === 'paid') {
            return $this->status('payment_received');
        }
        if (in_array($reconciliation, ['pending', 'processing', 'retryable'], true)
            || in_array($attempt['session_status'] ?? null, ['create_processing', 'create_retryable', 'ready', 'confirmed'], true)
            || $checkout === 'payment_started'
        ) {
            return $this->status('processing_payment');
        }
        if (in_array($checkout, ['pending', 'expired'], true)) {
            return $this->status('pending_payment');
        }

        return $this->status('under_review');
    }

    private function status(string $code): CustomerPurchaseVisibleStatus
    {
        [$label, $message] = match ($code) {
            'pending_payment' => ['Pendiente de pago', 'Tu compra aún no registra un pago confirmado.'],
            'processing_payment' => ['Procesando pago', 'Estamos confirmando el resultado de tu pago.'],
            'payment_rejected' => ['Pago rechazado', 'El pago no fue aprobado.'],
            'payment_received' => ['Pago recibido', 'Tu pago fue confirmado.'],
            'preparing_order' => ['Preparando pedido', 'Los minimarkets están preparando tu compra.'],
            'preparing_delivery' => ['Preparando despacho', 'Tu despacho está siendo preparado.'],
            'out_for_delivery' => ['En reparto', 'Tu compra va en camino.'],
            'delivered' => ['Entregado', 'La entrega fue completada.'],
            'cancelled' => ['Cancelado', 'La compra fue cancelada.'],
            default => ['En revisión', 'Estamos revisando el estado de tu compra.'],
        };

        return new CustomerPurchaseVisibleStatus($code, $label, $message);
    }
}
