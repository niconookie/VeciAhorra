<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Service;

use Throwable;
use VeciAhorra\Modules\Cart\Service\CartService;
use VeciAhorra\Modules\Orders\Services\OrderService;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;
use VeciAhorra\Modules\Reservations\Service\ReservationService;
use VeciAhorra\Modules\Checkout\Models\Checkout;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;
use VeciAhorra\Modules\Checkout\Repository\CheckoutOrderRepository;
use VeciAhorra\Modules\Payments\Service\IdempotencyService;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;
use VeciAhorra\Exceptions\ConflictException;
use VeciAhorra\Exceptions\RecordNotFoundException;

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
        ?CartService $cartService = null,
        ?CheckoutRepository $checkoutRepository = null,
        ?CheckoutOrderRepository $checkoutOrderRepository = null,
        ?OrderRepository $orderRepository = null,
        ?IdempotencyService $idempotencyService = null,
        ?PaymentSessionRepository $paymentSessionRepository = null,
        ?FulfillmentPolicy $fulfillmentPolicy = null
    ) {
        $this->cartService = $cartService ?? new CartService(
            new \VeciAhorra\Modules\Cart\Repository\CartRepository()
        );
        $this->checkoutRepository = $checkoutRepository
            ?? new CheckoutRepository();
        $this->checkoutOrderRepository = $checkoutOrderRepository
            ?? new CheckoutOrderRepository();
        $this->orderRepository = $orderRepository ?? new OrderRepository();
        $this->idempotencyService = $idempotencyService
            ?? new IdempotencyService();
        $this->paymentSessionRepository = $paymentSessionRepository
            ?? new PaymentSessionRepository();
        $this->fulfillmentPolicy = $fulfillmentPolicy ?? new FulfillmentPolicy();
    }

    private CheckoutRepository $checkoutRepository;
    private CheckoutOrderRepository $checkoutOrderRepository;
    private OrderRepository $orderRepository;
    private IdempotencyService $idempotencyService;
    private PaymentSessionRepository $paymentSessionRepository;
    private FulfillmentPolicy $fulfillmentPolicy;

    public function validate(array $payload): array
    {
        $validation = $this->validationService->validate($payload);
        if ($validation['valid']) {
            $this->fulfillmentPolicy->authorize(
                $this->method($payload),
                (string) $validation['summary']['total']
            );
        }
        return $validation;
    }

    public function initialize(array $payload): array
    {
        return $this->checkoutRepository->transaction(
            fn (): array => $this->initializeTransaction($payload)
        );
    }

    private function initializeTransaction(array $payload): array
    {
        $owner = $this->idempotencyService->owner($payload);
        $key = isset($payload['idempotency_key'])
            ? $this->idempotencyService->key((string) $payload['idempotency_key'])
            : null;
        $method = $this->method($payload);
        $ownerKey = $this->ownerKey($owner);
        if ($key !== null) {
            $existing = $this->checkoutRepository->findByIdempotency($ownerKey, $key);
            if ($existing !== null) {
                if (($existing['fulfillment_method'] ?? null) !== $method) {
                    throw new ConflictException(
                        'La clave de idempotencia fue usada con otro fulfillment_method.',
                        'idempotency_conflict'
                    );
                }
                return $this->replayedResult($existing);
            }
        }
        $validation = $this->validationService->validate($payload);

        if (! $validation['valid']) {
            return $this->invalidResult($validation);
        }
        $this->fulfillmentPolicy->authorize(
            $method,
            (string) $validation['summary']['total']
        );

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

        $checkout = $this->createPersistent(
            [...$payload, 'fulfillment_method' => $method],
            array_map(
                static fn (array $order): int => (int) $order['id'],
                $orders
            ),
            false
        );

        return [
            'valid' => true,
            'reservation_created' => true,
            'order_created' => true,
            'expires_at' => $expiresAt,
            'orders' => $orders,
            'reservations' => $reservations,
            'summary' => $validation['summary'],
            'checkout' => $checkout,
        ];
    }

    public function createPersistent(
        array $ownerInput,
        array $orderIds,
        bool $transactional = true
    ): array {
        $callback = function () use ($ownerInput, $orderIds): array {
            $owner = $this->idempotencyService->owner($ownerInput);
            $method = $this->method($ownerInput);
            $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

            if ($orderIds === [] || in_array(0, $orderIds, true)) {
                throw new \InvalidArgumentException(
                    'El checkout debe contener al menos una Order valida.'
                );
            }

            $orders = $this->orderRepository->findManyForUpdate($orderIds);

            if (count($orders) !== count($orderIds)) {
                throw new RecordNotFoundException('Una de las Orders no existe.');
            }

            if ($this->checkoutOrderRepository->findAttachedOrderIds($orderIds) !== []) {
                throw new ConflictException(
                    'Una de las Orders ya pertenece a un Checkout.',
                    'order_already_attached'
                );
            }

            $totalCents = 0;
            $expiresAt = null;

            foreach ($orders as $order) {
                if (
                    $owner['owner_type'] !== 'user'
                    || (int) $order['customer_id'] !== $owner['user_id']
                ) {
                    throw new \InvalidArgumentException(
                        'Todas las Orders deben pertenecer al mismo owner.'
                    );
                }

                $reservations = $this->reservationService->findByOrderId(
                    (int) $order['id']
                );

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
                    throw new \InvalidArgumentException(
                        'Todas las Orders deben tener reservas activas.'
                    );
                }

                if (
                    ($order['status'] ?? null) !== 'reserved'
                    || ! is_string($order['reservation_expires_at'] ?? null)
                    || $order['reservation_expires_at'] <= current_time('mysql')
                ) {
                    throw new \InvalidArgumentException(
                        'Todas las Orders deben estar reservadas y vigentes.'
                    );
                }

                $totalCents += $this->decimalToCents((string) $order['total']);
                $expiresAt = $expiresAt === null
                    ? $order['reservation_expires_at']
                    : min($expiresAt, $order['reservation_expires_at']);
            }

            $now = current_time('mysql');
            $key = isset($ownerInput['idempotency_key'])
                ? $this->idempotencyService->key((string) $ownerInput['idempotency_key'])
                : 'internal:' . hash('sha256', implode(',', $orderIds));
            $ownerKey = $this->ownerKey($owner);
            $fingerprint = hash('sha256', (string) wp_json_encode([
                'operation' => 'checkout.create.v1',
                'owner_key' => $ownerKey,
                'fulfillment_method' => $method,
                'currency' => 'CLP',
                'total_amount' => $this->formatCents($totalCents),
                'orders' => $orderIds,
            ], JSON_UNESCAPED_SLASHES));
            $id = $this->checkoutRepository->create([
                'public_id' => Checkout::publicId(),
                'owner_type' => $owner['owner_type'],
                'user_id' => $owner['user_id'],
                'session_id' => $owner['session_id'],
                'status' => Checkout::STATUS_PENDING,
                'fulfillment_method' => $method,
                'idempotency_owner_key' => $ownerKey,
                'idempotency_key' => $key,
                'request_fingerprint' => $fingerprint,
                'currency' => 'CLP',
                'total_amount' => $this->formatCents($totalCents),
                'created_at' => $now,
                'updated_at' => $now,
                'expires_at' => $expiresAt,
            ]);
            $this->checkoutOrderRepository->attach($id, $orderIds, $now);

            $stored = $this->checkoutRepository->find($id)
                ?? throw new \RuntimeException(
                    'No fue posible recuperar el Checkout.'
                );

            return $this->publicData($stored, count($orderIds));
        };

        return $transactional
            ? $this->checkoutRepository->transaction($callback)
            : $callback();
    }

    public function get(string $publicId, array $ownerInput): array
    {
        if (! Checkout::validPublicId($publicId)) {
            throw new \InvalidArgumentException('El checkout_id no es valido.');
        }

        $owner = $this->idempotencyService->owner($ownerInput);
        $checkout = $this->checkoutRepository->findOwnedByPublicId(
            $publicId,
            $owner
        );

        if ($checkout === null) {
            throw new RecordNotFoundException('El Checkout no existe.');
        }

        return $this->publicData(
            $checkout,
            count($this->checkoutOrderRepository->findOrderIds((int) $checkout['id']))
        );
    }

    private function publicData(array $checkout, int $orderCount): array
    {
        $activeSession = $this->paymentSessionRepository->findActive(
            (int) $checkout['id'],
            current_time('mysql')
        );

        return [
            'checkout_id' => (string) $checkout['public_id'],
            'status' => (string) $checkout['status'],
            'fulfillment_method' => $checkout['fulfillment_method'] ?? null,
            'currency' => (string) $checkout['currency'],
            'total_amount' => (string) $checkout['total_amount'],
            'order_count' => $orderCount,
            'payment_session_id' => $activeSession['public_id'] ?? null,
            'expires_at' => (string) $checkout['expires_at'],
            'created_at' => (string) $checkout['created_at'],
            'updated_at' => (string) $checkout['updated_at'],
        ];
    }

    private function formatCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
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

    private function method(array $payload): string
    {
        $method = $payload['fulfillment_method'] ?? null;
        if ($method === null && ! isset($payload['idempotency_key'])) {
            return FulfillmentPolicy::PICKUP;
        }
        if (! is_string($method)) {
            throw new \InvalidArgumentException('fulfillment_method es obligatorio.');
        }
        return $method;
    }

    private function ownerKey(array $owner): string
    {
        return hash('sha256', $owner['owner_type'] . '|' . (
            $owner['owner_type'] === 'user' ? (string) $owner['user_id'] : (string) $owner['session_id']
        ));
    }

    private function replayedResult(array $checkout): array
    {
        $orderIds = $this->checkoutOrderRepository->findOrderIds((int) $checkout['id']);
        return [
            'valid' => true,
            'reservation_created' => true,
            'order_created' => true,
            'reused' => true,
            'expires_at' => $checkout['expires_at'],
            'orders' => $this->orderRepository->findMany($orderIds),
            'reservations' => [],
            'summary' => ['total' => $checkout['total_amount']],
            'checkout' => $this->publicData($checkout, count($orderIds)),
        ];
    }
}
