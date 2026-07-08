<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\CustomerPanel\Service;

use InvalidArgumentException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;
use VeciAhorra\Modules\Payments\Repository\PaymentRepository;

final class CustomerPanelService
{
    public function __construct(
        private OrderRepository $orderRepository,
        private PaymentRepository $paymentRepository
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listOrders(int $customerId): array
    {
        $this->assertCustomer($customerId);

        return array_map(
            fn (array $order): array => $this->serializeSummary($order),
            $this->orderRepository->list(['customer_id' => $customerId])
        );
    }

    public function getOrder(int $customerId, int $orderId): array
    {
        $this->assertCustomer($customerId);

        if ($orderId <= 0) {
            throw new InvalidArgumentException(
                'El identificador del pedido debe ser positivo.'
            );
        }

        $order = $this->orderRepository->findForCustomer(
            $orderId,
            $customerId
        );

        if ($order === null) {
            throw new RecordNotFoundException(
                'El pedido solicitado no existe.'
            );
        }

        $payment = $this->paymentRepository->findByOrderId($orderId);
        $seller = $this->orderRepository->findSeller(
            (int) $order['minimarket_id']
        );

        return [
            'order_id' => (int) $order['id'],
            'status' => (string) $order['status'],
            'visible_status' => $this->visibleStatus((string) $order['status']),
            'minimarket_id' => (int) $order['minimarket_id'],
            'seller' => [
                'id' => (int) $order['minimarket_id'],
                'name' => $seller['business_name'] ?? null,
            ],
            'items' => array_map(
                fn (array $item): array => $this->serializeItem($item),
                $this->orderRepository->findItems($orderId)
            ),
            'total' => (string) $order['total'],
            'payment' => $this->serializePayment($payment),
            'reservation_expires_at' => $order['reservation_expires_at'],
            'created_at' => (string) $order['created_at'],
            'updated_at' => (string) $order['updated_at'],
        ];
    }

    private function serializeSummary(array $order): array
    {
        $payment = $this->paymentRepository->findByOrderId((int) $order['id']);

        return [
            'order_id' => (int) $order['id'],
            'status' => (string) $order['status'],
            'visible_status' => $this->visibleStatus((string) $order['status']),
            'total' => (string) $order['total'],
            'minimarket_id' => (int) $order['minimarket_id'],
            'reservation_expires_at' => $order['reservation_expires_at'],
            'created_at' => (string) $order['created_at'],
            'payment_status' => $payment['status'] ?? null,
            'payment_method' => $payment['provider'] ?? null,
        ];
    }

    private function serializeItem(array $item): array
    {
        return [
            'id' => (int) $item['id'],
            'product' => [
                'id' => (int) $item['product_id'],
                'name' => $item['product_name'],
                'slug' => $item['product_slug'],
                'sku' => $item['product_sku'],
            ],
            'inventory_id' => (int) $item['inventory_id'],
            'quantity' => (int) $item['quantity'],
            'unit_price' => (string) $item['unit_price'],
            'subtotal' => (string) $item['subtotal'],
        ];
    }

    private function serializePayment(?array $payment): ?array
    {
        if ($payment === null) {
            return null;
        }

        return [
            'id' => (int) $payment['id'],
            'reference' => (string) $payment['payment_reference'],
            'status' => (string) $payment['status'],
            'method' => $payment['provider'],
            'amount' => (string) $payment['amount'],
            'currency' => (string) $payment['currency'],
            'paid_at' => $payment['paid_at'],
        ];
    }

    private function visibleStatus(string $status): string
    {
        return match ($status) {
            'reserved' => 'Reservado',
            'paid' => 'Pagado',
            default => 'En proceso',
        };
    }

    private function assertCustomer(int $customerId): void
    {
        if ($customerId <= 0) {
            throw new InvalidArgumentException(
                'El cliente autenticado no es valido.'
            );
        }
    }
}
