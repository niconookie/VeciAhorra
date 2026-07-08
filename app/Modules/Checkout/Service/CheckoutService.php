<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Service;

use Throwable;
use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Orders\Services\OrderService;
use VeciAhorra\Modules\Reservations\Service\ReservationService;

/**
 * Orquesta validacion, reservas temporales y pedidos reservados.
 */
final class CheckoutService
{
    private CartService $cartService;

    public function __construct(
        private CheckoutValidationService $validationService,
        private ReservationService $reservationService,
        private OrderService $orderService,
        ?CartService $cartService = null
    ) {
        $this->cartService = $cartService ?? new CartService(
            new \VeciAhorra\Modules\Cart\Repository\CartRepository()
        );
    }

    public function validate(array $payload): array
    {
        return $this->validationService->validate($payload);
    }

    public function initialize(array $payload): array
    {
        $validation = $this->validationService->validate($payload);

        if (! $validation['valid']) {
            return $this->invalidResult($validation);
        }

        $customerId = $payload['user_id'] ?? null;

        if (! is_int($customerId) || $customerId <= 0) {
            $validation['valid'] = false;
            $validation['errors'][] = [
                'code' => 'guest_checkout_not_supported',
                'message' => 'El checkout requiere un usuario autenticado.',
            ];

            return $this->invalidResult($validation);
        }

        $reservations = $this->reservationService->createForCheckout(
            $validation['items']
        );
        $expirationDates = array_column($reservations, 'expires_at');
        $expiresAt = $expirationDates === []
            ? null
            : min($expirationDates);
        $orders = [];

        try {
            foreach ($this->groupItems($validation['items']) as $group) {
                $order = $this->orderService->createFromReservedItems(
                    $customerId,
                    $group['minimarket_id'],
                    $group['items'],
                    $group['total'],
                    (string) $expiresAt
                );
                $orders[] = $order;
                $reservationIds = array_map(
                    static fn (array $reservation): int =>
                        (int) $reservation['id'],
                    array_values(array_filter(
                        $reservations,
                        static fn (array $reservation): bool =>
                            (int) $reservation['minimarket_id']
                                === $group['minimarket_id']
                    ))
                );
                $this->reservationService->assignToOrder(
                    (int) $order['id'],
                    $reservationIds
                );

                foreach ($reservations as &$reservation) {
                    if (in_array(
                        (int) $reservation['id'],
                        $reservationIds,
                        true
                    )) {
                        $reservation['order_id'] = (string) $order['id'];
                    }
                }
                unset($reservation);
            }

            $this->cartService->clearCart($payload);
        } catch (Throwable $exception) {
            try {
                $this->orderService->cancelOrders(array_map(
                    static fn (array $order): int => (int) $order['id'],
                    $orders
                ));
            } finally {
                $this->reservationService->cancelCheckout(
                    $reservations,
                    $validation['items']
                );
            }

            throw $exception;
        }

        return [
            'valid' => true,
            'reservation_created' => true,
            'order_created' => true,
            'expires_at' => $expiresAt,
            'orders' => $orders,
            'reservations' => $reservations,
            'summary' => $validation['summary'],
        ];
    }

    private function invalidResult(array $validation): array
    {
        return [
            ...$validation,
            'reservation_created' => false,
            'order_created' => false,
            'expires_at' => null,
            'orders' => [],
            'reservations' => [],
        ];
    }

    /**
     * @return list<array{minimarket_id: int, items: array, total: string}>
     */
    private function groupItems(array $items): array
    {
        $groups = [];

        foreach ($items as $item) {
            $minimarketId = (int) $item['minimarket_id'];
            $groups[$minimarketId] ??= [
                'minimarket_id' => $minimarketId,
                'items' => [],
                'total_cents' => 0,
            ];
            $groups[$minimarketId]['items'][] = $item;
            $groups[$minimarketId]['total_cents'] +=
                $this->decimalToCents((string) $item['subtotal']);
        }

        ksort($groups);

        return array_values(array_map(
            static function (array $group): array {
                $cents = $group['total_cents'];
                unset($group['total_cents']);
                $group['total'] = sprintf(
                    '%d.%02d',
                    intdiv($cents, 100),
                    $cents % 100
                );

                return $group;
            },
            $groups
        ));
    }

    private function decimalToCents(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100)
            + (int) str_pad($decimal, 2, '0');
    }
}
