<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Service;

use InvalidArgumentException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Orders\Services\OrderService;
use VeciAhorra\Modules\Payments\Gateway\PaymentConfirmationGatewayInterface;
use VeciAhorra\Modules\Payments\Repository\PaymentRepository;
use VeciAhorra\Modules\Reservations\Service\ReservationService;

final class PaymentConfirmationService
{
    public function __construct(
        private PaymentRepository $repository,
        private PaymentConfirmationGatewayInterface $gateway,
        private OrderService $orderService,
        private ReservationService $reservationService
    ) {
    }

    public function confirm(
        string $provider,
        string $providerReference
    ): array {
        $payment = $this->repository->findByProviderReference(
            $providerReference
        );

        if ($payment === null) {
            throw new RecordNotFoundException(
                'El pago solicitado no existe.'
            );
        }

        $this->assertProvider($payment, $provider);

        if ($this->isTerminal((string) $payment['status'])) {
            return $this->currentResult($payment);
        }

        if (($payment['status'] ?? null) !== PaymentService::STATUS_PENDING) {
            throw new InvalidArgumentException(
                'El pago no se encuentra pendiente.'
            );
        }

        $confirmation = $this->gateway->confirmPayment($providerReference);

        return $this->repository->transaction(function () use (
            $provider,
            $providerReference,
            $confirmation
        ): array {
            $payment = $this->repository->findByProviderReferenceForUpdate(
                $providerReference
            );

            if ($payment === null) {
                throw new RecordNotFoundException(
                    'El pago solicitado no existe.'
                );
            }

            $this->assertProvider($payment, $provider);

            if ($this->isTerminal((string) $payment['status'])) {
                return $this->currentResult($payment);
            }

            if (($payment['status'] ?? null) !== PaymentService::STATUS_PENDING) {
                throw new InvalidArgumentException(
                    'El pago no se encuentra pendiente.'
                );
            }

            $now = current_time('mysql');

            if (! $confirmation->isSuccessful()) {
                $this->repository->updateStatus(
                    (int) $payment['id'],
                    PaymentService::STATUS_PENDING,
                    'failed',
                    null,
                    $now
                );

                return [
                    'payment_id' => (int) $payment['id'],
                    'status' => 'failed',
                    'paid_at' => null,
                    'orders_updated' => 0,
                    'reservations_confirmed' => 0,
                ];
            }

            $orderIds = array_map('intval', $payment['order_ids'] ?? []);
            $reservationsConfirmed = $this->reservationService
                ->confirmForOrders($orderIds);
            $ordersUpdated = $this->orderService->markPaid($orderIds);
            $this->repository->updateStatus(
                (int) $payment['id'],
                PaymentService::STATUS_PENDING,
                'paid',
                $now,
                $now
            );

            return [
                'payment_id' => (int) $payment['id'],
                'status' => 'paid',
                'paid_at' => $now,
                'orders_updated' => $ordersUpdated,
                'reservations_confirmed' => $reservationsConfirmed,
            ];
        });
    }

    private function assertProvider(array $payment, string $provider): void
    {
        if (
            ($payment['provider'] ?? null) !== $provider
        ) {
            throw new InvalidArgumentException(
                'El proveedor no corresponde al pago.'
            );
        }
    }

    private function isTerminal(string $status): bool
    {
        return in_array($status, ['paid', 'failed'], true);
    }

    private function currentResult(array $payment): array
    {
        return [
            'payment_id' => (int) $payment['id'],
            'status' => (string) $payment['status'],
            'paid_at' => $payment['paid_at'],
            'orders_updated' => 0,
            'reservations_confirmed' => 0,
        ];
    }
}
