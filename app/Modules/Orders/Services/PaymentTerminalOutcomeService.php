<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use VeciAhorra\Modules\Checkout\Models\Checkout;
use VeciAhorra\Modules\Checkout\Repository\CheckoutOrderRepository;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;
use VeciAhorra\Modules\Inventory\Services\InventoryLockService;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;
use VeciAhorra\Modules\Payments\Contracts\PaymentTerminalOutcomeInterface;
use VeciAhorra\Modules\Payments\Models\PaymentSession;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;
use VeciAhorra\Modules\Reservations\Repository\ReservationRepository;

final class PaymentTerminalOutcomeService implements PaymentTerminalOutcomeInterface
{
    public function __construct(
        private readonly CheckoutRepository $checkouts = new CheckoutRepository(),
        private readonly CheckoutOrderRepository $checkoutOrders = new CheckoutOrderRepository(),
        private readonly PaymentSessionRepository $sessions = new PaymentSessionRepository(),
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly ReservationRepository $reservations = new ReservationRepository(),
        private readonly InventoryLockService $inventory = new InventoryLockService()
    ) {}

    public function cancel(int $paymentSessionId, string $checkoutPublicId): void
    {
        $this->checkouts->transaction(function () use ($paymentSessionId, $checkoutPublicId): void {
            $checkout = $this->checkouts->findByPublicIdForUpdate($checkoutPublicId);
            $session = $this->sessions->findForUpdate($paymentSessionId);
            if ($checkout === null || $session === null
                || (int) $session['checkout_id'] !== (int) $checkout['id']) {
                throw new \RuntimeException('La autoridad local del retorno no existe.');
            }
            if (($session['status'] ?? null) === PaymentSession::STATUS_CANCELLED
                && ($checkout['status'] ?? null) === Checkout::STATUS_CANCELLED) {
                return;
            }
            if (($session['status'] ?? null) !== PaymentSession::STATUS_READY
                || ($checkout['status'] ?? null) !== Checkout::STATUS_PAYMENT_STARTED) {
                throw new \RuntimeException('La autoridad local del retorno cambio.');
            }

            $orderIds = $this->checkoutOrders->findOrderIds((int) $checkout['id'], true);
            $orders = $this->orders->findManyForUpdate($orderIds);
            $reservations = $this->reservations->findByOrderIdsForUpdate($orderIds);
            if (count($orders) !== count($orderIds)) {
                throw new \RuntimeException('El conjunto de pedidos no coincide.');
            }
            foreach ($orders as $order) {
                if (($order['status'] ?? null) !== 'reserved') {
                    throw new \RuntimeException('Un pedido ya no puede cancelarse.');
                }
            }
            $active = array_values(array_filter(
                $reservations,
                static fn (array $row): bool => ($row['status'] ?? null) === 'active'
            ));
            foreach ($active as $reservation) {
                if (! $this->inventory->releaseStock(
                    (int) $reservation['inventory_id'],
                    (int) $reservation['quantity']
                )) {
                    throw new \RuntimeException('No fue posible restituir inventario.');
                }
            }
            $now = current_time('mysql');
            $this->reservations->markReleased(
                array_map(static fn (array $row): int => (int) $row['id'], $active),
                $now
            );
            $this->orders->markCancelled($orderIds, $now);
            if (! $this->sessions->cancelReady($paymentSessionId, $now)
                || ! $this->checkouts->updateStatus(
                    (int) $checkout['id'], Checkout::STATUS_PAYMENT_STARTED,
                    Checkout::STATUS_CANCELLED, $now
                )) {
                throw new \RuntimeException('No fue posible cerrar el retorno.');
            }
        });
    }
}
