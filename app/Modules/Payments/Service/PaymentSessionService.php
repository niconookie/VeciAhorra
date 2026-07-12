<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\GatewaySessionResult;
use VeciAhorra\Modules\Payments\Gateway\PaymentSessionContext;
use VeciAhorra\Modules\Payments\Models\Payment;
use VeciAhorra\Modules\Payments\Repository\PaymentRepository;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;
use VeciAhorra\Modules\Payments\Models\PaymentSession;
use VeciAhorra\Modules\Checkout\Models\Checkout;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;
use VeciAhorra\Modules\Checkout\Repository\CheckoutOrderRepository;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;
use VeciAhorra\Exceptions\ConflictException;
use VeciAhorra\Modules\Reservations\Service\ReservationService;

final class PaymentSessionService
{
    public function __construct(
        private PaymentRepository $repository,
        private PaymentGatewayInterface $gateway,
        ?PaymentSessionRepository $sessionRepository = null,
        ?CheckoutRepository $checkoutRepository = null,
        ?CheckoutOrderRepository $checkoutOrderRepository = null,
        ?OrderRepository $orderRepository = null,
        ?IdempotencyService $idempotencyService = null,
        ?ReservationService $reservationService = null
    ) {
        $this->sessionRepository = $sessionRepository
            ?? new PaymentSessionRepository();
        $this->checkoutRepository = $checkoutRepository
            ?? new CheckoutRepository();
        $this->checkoutOrderRepository = $checkoutOrderRepository
            ?? new CheckoutOrderRepository();
        $this->orderRepository = $orderRepository ?? new OrderRepository();
        $this->idempotencyService = $idempotencyService
            ?? new IdempotencyService();
        $this->reservationService = $reservationService
            ?? new ReservationService();
    }

    private PaymentSessionRepository $sessionRepository;
    private CheckoutRepository $checkoutRepository;
    private CheckoutOrderRepository $checkoutOrderRepository;
    private OrderRepository $orderRepository;
    private IdempotencyService $idempotencyService;
    private ReservationService $reservationService;

    public function create(int $paymentId): array
    {
        $stored = $this->repository->find($paymentId);

        if ($stored === null) {
            throw new RecordNotFoundException(
                'El pago solicitado no existe.'
            );
        }

        $payment = Payment::fromArray($stored);

        if ($payment->status !== PaymentService::STATUS_PENDING) {
            throw new InvalidArgumentException(
                'Solo los pagos pendientes pueden crear una sesion.'
            );
        }

        $maximumExpiration = $payment->expiresAt
            ?? (new DateTimeImmutable($payment->createdAt, wp_timezone()))
                ->modify('+15 minutes')
                ->format('Y-m-d H:i:s');
        $session = $this->gateway->createSession(new PaymentSessionContext(
            $payment->paymentReference,
            $payment->paymentReference,
            $payment->amount,
            $payment->currency,
            $maximumExpiration,
            $payment->paymentReference
        ));

        if (
            $session->status !== GatewaySessionResult::STATUS_READY
            || $session->redirectUrl === null
            || filter_var(
                $session->redirectUrl,
                FILTER_VALIDATE_URL
            ) === false
        ) {
            throw new InvalidArgumentException(
                'El proveedor devolvio una sesion de pago invalida.'
            );
        }

        $this->repository->updateSessionData(
            $payment->id,
            $session->provider,
            $session->providerSessionId,
            $session->expiresAt,
            current_time('mysql')
        );

        return [
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'provider' => $session->provider,
            'provider_reference' => $session->providerSessionId,
            'payment_url' => $session->redirectUrl,
            'expires_at' => str_replace(' ', 'T', $session->expiresAt),
        ];
    }

    public function start(
        string $checkoutPublicId,
        string $idempotencyKey,
        array $ownerInput
    ): array {
        if (! Checkout::validPublicId($checkoutPublicId)) {
            throw new InvalidArgumentException('El checkout_id no es valido.');
        }

        $key = $this->idempotencyService->key($idempotencyKey);
        $owner = $this->idempotencyService->owner($ownerInput);

        $outcome = $this->checkoutRepository->transaction(function () use (
            $checkoutPublicId,
            $key,
            $owner
        ): array {
            $checkout = $this->checkoutRepository->findOwnedByPublicId(
                $checkoutPublicId,
                $owner,
                true
            );

            if ($checkout === null) {
                throw new RecordNotFoundException('El Checkout no existe.');
            }

            $checkoutId = (int) $checkout['id'];
            $orderIds = $this->checkoutOrderRepository->findOrderIds(
                $checkoutId,
                true
            );
            $orders = $this->orderRepository->findManyForUpdate($orderIds);
            $amount = $this->ordersAmount($orders, $orderIds);
            $this->assertActiveReservations($orderIds);
            $fingerprint = $this->idempotencyService->fingerprint(
                $checkoutPublicId,
                $owner,
                (string) $checkout['currency'],
                $amount,
                $orderIds
            );
            $byKey = $this->sessionRepository->findByKey($checkoutId, $key);

            if ($byKey !== null) {
                if (! hash_equals(
                    (string) $byKey['request_fingerprint'],
                    $fingerprint
                )) {
                    throw new ConflictException(
                        'La clave de idempotencia fue usada con otros datos.',
                        'idempotency_conflict'
                    );
                }

                return $this->prepareGatewaySession(
                    $byKey,
                    $checkoutPublicId,
                    true
                );
            }

            $now = current_time('mysql');

            if (
                in_array($checkout['status'], [
                    Checkout::STATUS_EXPIRED,
                    Checkout::STATUS_CANCELLED,
                ], true)
                || (string) $checkout['expires_at'] <= $now
            ) {
                throw new InvalidArgumentException(
                    'El Checkout esta expirado o cancelado.'
                );
            }

            if ((string) $checkout['total_amount'] !== $amount) {
                throw new InvalidArgumentException(
                    'El total del Checkout no coincide con sus Orders.'
                );
            }

            $active = $this->sessionRepository->findActive($checkoutId, $now);

            if ($active !== null) {
                return $this->prepareGatewaySession(
                    $active,
                    $checkoutPublicId,
                    true
                );
            }

            $sessionId = $this->sessionRepository->create([
                'public_id' => PaymentSession::publicId(),
                'checkout_id' => $checkoutId,
                'idempotency_key' => $key,
                'request_fingerprint' => $fingerprint,
                'status' => PaymentSession::STATUS_PENDING,
                'provider' => null,
                'provider_session_id' => null,
                'redirect_url' => null,
                'currency' => (string) $checkout['currency'],
                'amount' => $amount,
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'expires_at' => (string) $checkout['expires_at'],
            ]);

            if ($checkout['status'] === Checkout::STATUS_PENDING) {
                $this->checkoutRepository->updateStatus(
                    $checkoutId,
                    Checkout::STATUS_PENDING,
                    Checkout::STATUS_PAYMENT_STARTED,
                    $now
                );
            }

            $session = $this->sessionRepository->find($sessionId)
                ?? throw new \RuntimeException(
                    'No fue posible recuperar la sesion de pago.'
                );

            return $this->prepareGatewaySession(
                $session,
                $checkoutPublicId,
                false
            );
        });

        if (($outcome['rejected'] ?? false) === true) {
            throw new InvalidArgumentException(
                'El gateway rechazo la creacion de la sesion de pago.'
            );
        }

        return $outcome['data'];
    }

    public function get(string $publicId, array $ownerInput): array
    {
        if (! PaymentSession::validPublicId($publicId)) {
            throw new InvalidArgumentException(
                'El payment_session_id no es valido.'
            );
        }

        $session = $this->sessionRepository->findOwnedByPublicId(
            $publicId,
            $this->idempotencyService->owner($ownerInput)
        );

        if ($session === null) {
            throw new RecordNotFoundException('La sesion de pago no existe.');
        }

        return $this->publicData(
            $session,
            (string) $session['checkout_public_id'],
            null
        );
    }

    private function ordersAmount(array $orders, array $orderIds): string
    {
        if ($orderIds === [] || count($orders) !== count($orderIds)) {
            throw new InvalidArgumentException(
                'El Checkout no contiene Orders pagables.'
            );
        }

        $total = 0;

        foreach ($orders as $order) {
            if (($order['status'] ?? null) !== 'reserved') {
                throw new InvalidArgumentException(
                    'Las Orders del Checkout no son pagables.'
                );
            }

            [$whole, $decimal] = array_pad(
                explode('.', (string) $order['total'], 2),
                2,
                ''
            );
            $total += ((int) $whole * 100)
                + (int) str_pad($decimal, 2, '0');
        }

        return sprintf('%d.%02d', intdiv($total, 100), $total % 100);
    }

    private function assertActiveReservations(array $orderIds): void
    {
        $now = current_time('mysql');

        foreach ($orderIds as $orderId) {
            $reservations = $this->reservationService->findByOrderId($orderId);

            if (
                $reservations === []
                || count(array_filter(
                    $reservations,
                    static fn (array $reservation): bool =>
                        ($reservation['status'] ?? null) === 'active'
                        && (string) $reservation['expires_at'] > $now
                )) !== count($reservations)
            ) {
                throw new InvalidArgumentException(
                    'Las Orders no tienen reservas activas y vigentes.'
                );
            }
        }
    }

    private function publicData(
        array $session,
        string $checkoutPublicId,
        ?bool $reused
    ): array {
        $data = [
            'payment_session_id' => (string) $session['public_id'],
            'checkout_id' => $checkoutPublicId,
            'status' => (string) $session['status'],
            'provider' => $session['provider'],
            'redirect_url' => $session['redirect_url'],
            'currency' => (string) $session['currency'],
            'amount' => (string) $session['amount'],
            'expires_at' => (string) $session['expires_at'],
            'created_at' => (string) $session['created_at'],
        ];

        if ($reused !== null) {
            $data['reused'] = $reused;
        }

        return $data;
    }

    /** @return array{rejected: bool, data: array<string, mixed>} */
    private function prepareGatewaySession(
        array $session,
        string $checkoutPublicId,
        bool $reused
    ): array {
        if (($session['status'] ?? null) !== PaymentSession::STATUS_PENDING) {
            return [
                'rejected' => false,
                'data' => $this->publicData(
                    $session,
                    $checkoutPublicId,
                    $reused
                ),
            ];
        }

        $result = $this->gateway->createSession(new PaymentSessionContext(
            (string) $session['public_id'],
            $checkoutPublicId,
            (string) $session['amount'],
            (string) $session['currency'],
            (string) $session['expires_at'],
            (string) $session['idempotency_key']
        ));

        if ($result->expiresAt > (string) $session['expires_at']) {
            throw new InvalidArgumentException(
                'El gateway devolvio una expiracion fuera del Checkout.'
            );
        }

        $status = match ($result->status) {
            GatewaySessionResult::STATUS_READY => PaymentSession::STATUS_READY,
            GatewaySessionResult::STATUS_EXPIRED => PaymentSession::STATUS_EXPIRED,
            GatewaySessionResult::STATUS_REJECTED => PaymentSession::STATUS_PENDING,
            default => throw new InvalidArgumentException(
                'El gateway devolvio un estado desconocido.'
            ),
        };
        $this->sessionRepository->updateGatewayResult(
            (int) $session['id'],
            PaymentSession::STATUS_PENDING,
            $status,
            $result->provider,
            $result->providerSessionId,
            $result->redirectUrl,
            $result->status === GatewaySessionResult::STATUS_REJECTED
                ? (string) $session['expires_at']
                : $result->expiresAt,
            current_time('mysql')
        );
        $stored = $this->sessionRepository->find((int) $session['id'])
            ?? throw new \RuntimeException(
                'No fue posible recuperar el resultado del gateway.'
            );

        return [
            'rejected' => $result->status
                === GatewaySessionResult::STATUS_REJECTED,
            'data' => $this->publicData(
                $stored,
                $checkoutPublicId,
                $reused
            ),
        ];
    }
}
