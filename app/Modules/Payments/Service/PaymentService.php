<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Service;

use Throwable;
use InvalidArgumentException;
use VeciAhorra\Modules\Orders\Services\OrderService;
use VeciAhorra\Modules\Payments\Repository\PaymentRepository;
use VeciAhorra\Modules\Reservations\Service\ReservationService;

final class PaymentService
{
    public const STATUS_PENDING = 'pending';

    private OrderService $orderService;
    private ReservationService $reservationService;

    public function __construct(
        private PaymentRepository $repository,
        ?OrderService $orderService = null,
        ?ReservationService $reservationService = null
    ) {
        $this->orderService = $orderService ?? new OrderService();
        $this->reservationService = $reservationService
            ?? new ReservationService();
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->repository->list();
    }

    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    public function create(array $data): array
    {
        $existing = $this->repository->findByOrderIds($data['order_ids']);

        if ($existing !== null) {
            $this->assertSamePayment($existing, $data);

            return $existing;
        }

        $this->assertPayableOrders($data);
        $now = current_time('mysql');
        $paymentId = null;

        try {
            $paymentId = $this->repository->create([
                'payment_reference' => $this->reference(),
                'customer_id' => $data['customer_id'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'status' => self::STATUS_PENDING,
                'provider' => $data['provider'],
                'provider_reference' => null,
                'expires_at' => null,
                'paid_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->repository->attachOrders(
                $paymentId,
                $data['order_ids'],
                $now
            );
        } catch (Throwable $exception) {
            if ($paymentId !== null) {
                $this->repository->delete($paymentId);
            }

            throw $exception;
        }

        return $this->repository->find($paymentId) ?? throw new \RuntimeException(
            'No fue posible recuperar el pago creado.'
        );
    }

    private function reference(): string
    {
        return 'PAY-' . strtoupper(str_replace('-', '', wp_generate_uuid4()));
    }

    private function assertPayableOrders(array $data): void
    {
        $totalCents = 0;

        foreach ($data['order_ids'] as $orderId) {
            $order = $this->orderService->find($orderId);

            if ($order === null) {
                throw new InvalidArgumentException(
                    'Uno de los pedidos no existe.'
                );
            }

            if (
                ($order['status'] ?? null) !== 'reserved'
                || (int) $order['customer_id'] !== $data['customer_id']
            ) {
                throw new InvalidArgumentException(
                    'Los pedidos no son pagables por este cliente.'
                );
            }

            $reservations = $this->reservationService->findByOrderId($orderId);

            if (
                $reservations === []
                || count(array_filter(
                    $reservations,
                    static fn (array $reservation): bool =>
                        ($reservation['status'] ?? null) === 'active'
                        && (string) $reservation['expires_at']
                            > current_time('mysql')
                )) !== count($reservations)
            ) {
                throw new InvalidArgumentException(
                    'Los pedidos no tienen reservas activas.'
                );
            }

            $totalCents += $this->decimalToCents((string) $order['total']);
        }

        if ($totalCents !== $this->decimalToCents($data['amount'])) {
            throw new InvalidArgumentException(
                'El monto del pago no coincide con los pedidos.'
            );
        }
    }

    private function assertSamePayment(array $payment, array $data): void
    {
        $storedOrderIds = array_map('intval', $payment['order_ids'] ?? []);
        $requestedOrderIds = $data['order_ids'];
        sort($storedOrderIds);
        sort($requestedOrderIds);

        if (
            $storedOrderIds !== $requestedOrderIds
            || (int) $payment['customer_id'] !== $data['customer_id']
            || $this->decimalToCents((string) $payment['amount'])
                !== $this->decimalToCents($data['amount'])
            || (string) $payment['currency'] !== $data['currency']
        ) {
            throw new InvalidArgumentException(
                'Los pedidos ya pertenecen a otro intento de pago.'
            );
        }
    }

    private function decimalToCents(string $amount): int
    {
        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($decimal, 2, '0');
    }
}
