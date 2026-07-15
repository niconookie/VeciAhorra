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
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Reservations\Service\ReservationService;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayConfiguration;
use VeciAhorra\Modules\Payments\Gateway\WebpayTransactionReference;
use VeciAhorra\Modules\Payments\Gateway\WebpayPaymentGateway;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Support\WordPressSiteScope;
use VeciAhorra\Modules\Reservations\Repository\ReservationRepository;
use Throwable;
use VeciAhorra\Modules\Payments\Orchestration\WebpayCreateRecovery;

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
        ?ReservationService $reservationService = null,
        ?PaymentOriginContextRepository $originRepository = null,
        ?ReservationRepository $reservationRepository = null
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
        $this->originRepository = $originRepository
            ?? new PaymentOriginContextRepository();
        $this->reservationRepository = $reservationRepository
            ?? new ReservationRepository();
    }

    private PaymentSessionRepository $sessionRepository;
    private CheckoutRepository $checkoutRepository;
    private CheckoutOrderRepository $checkoutOrderRepository;
    private OrderRepository $orderRepository;
    private IdempotencyService $idempotencyService;
    private ReservationService $reservationService;
    private PaymentOriginContextRepository $originRepository;
    private ReservationRepository $reservationRepository;

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
            $this->assertOrderOwnership($checkout, $orders, $orderIds);
            $amount = $this->ordersAmount($orders, $orderIds);
            $this->assertActiveReservations($orderIds);
            $fingerprint = $this->idempotencyService->fingerprint(
                $checkoutPublicId,
                $owner,
                (string) $checkout['currency'],
                $amount,
                $orderIds,
                isset($checkout['fulfillment_method'])
                    ? (string) $checkout['fulfillment_method']
                    : null
            );
            $now = current_time('mysql');

            if (in_array($checkout['status'], [
                Checkout::STATUS_EXPIRED,
                Checkout::STATUS_CANCELLED,
            ], true) || (string) $checkout['expires_at'] <= $now) {
                throw new InvalidArgumentException(
                    'El Checkout esta expirado o cancelado.'
                );
            }
            if ((string) $checkout['currency'] !== 'CLP'
                || ! in_array(
                    $checkout['fulfillment_method'] ?? null,
                    ['pickup', 'delivery'],
                    true
                )) {
                throw new InvalidArgumentException(
                    'La moneda o fulfillment del Checkout no es pagable.'
                );
            }
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

                if ((string) $byKey['expires_at'] <= $now) {
                    $this->sessionRepository->expirePending(
                        (int) $byKey['id'],
                        $now
                    );
                    $byKey = $this->sessionRepository->find(
                        (int) $byKey['id']
                    ) ?? $byKey;
                }
                $this->assertDurableOrigin($byKey, $checkout);
                return ['data' => $this->localAttemptData(
                    $byKey, $checkoutPublicId, true
                )];
            }

            if ((string) $checkout['total_amount'] !== $amount) {
                throw new InvalidArgumentException(
                    'El total del Checkout no coincide con sus Orders.'
                );
            }

            $active = $this->sessionRepository->findActive($checkoutId, $now);

            if ($active !== null) {
                if (! hash_equals(
                    (string) $active['request_fingerprint'],
                    $fingerprint
                )) {
                    throw new ConflictException(
                        'Existe un intento activo con otra autoridad.',
                        'state_conflict'
                    );
                }
                $this->assertDurableOrigin($active, $checkout);
                return ['data' => $this->localAttemptData(
                    $active, $checkoutPublicId, true
                )];
            }

            $publicId = PaymentSession::publicId();
            $sessionId = $this->sessionRepository->create([
                'public_id' => $publicId,
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

            $this->originRepository->create($this->origin(
                $checkout,
                $publicId,
                $key,
                $amount,
                $now
            ));

            return ['data' => $this->localAttemptData(
                $session, $checkoutPublicId, false
            )];
        });

        return $this->initializePublicAttempt(
            (string) $outcome['data']['payment_session_id'],
            $ownerInput,
            (bool) $outcome['data']['reused']
        );
    }

    public function initializePublicAttempt(
        string $publicId,
        array $ownerInput,
        bool $reused = true
    ): array {
        $owner = $this->idempotencyService->owner($ownerInput);
        $owned = $this->sessionRepository->findOwnedByPublicId($publicId, $owner);
        if ($owned === null) {
            throw new RecordNotFoundException('La sesion de pago no existe.');
        }
        if ($owned['status'] === PaymentSession::STATUS_READY) {
            return $this->publicData($owned, (string) $owned['checkout_public_id'], true, true);
        }
        if (in_array($owned['status'], [
            PaymentSession::STATUS_CREATE_PROCESSING,
            PaymentSession::STATUS_CREATE_AMBIGUOUS,
            PaymentSession::STATUS_CREATE_FAILED,
            PaymentSession::STATUS_EXPIRED,
            PaymentSession::STATUS_CANCELLED,
            PaymentSession::STATUS_CONFIRMED,
        ], true)) {
            return $this->publicData($owned, (string) $owned['checkout_public_id'], $reused, true);
        }

        $claimOwner = bin2hex(random_bytes(24));
        $now = current_time('mysql');
        $leaseExpiresAt = (new DateTimeImmutable($now, wp_timezone()))
            ->modify('+2 minutes')->format('Y-m-d H:i:s');
        $claimed = $this->checkoutRepository->transaction(function () use (
            $owned, $claimOwner, $now, $leaseExpiresAt
        ): ?array {
            $locked = $this->sessionRepository->findForUpdate((int) $owned['id']);
            if ($locked === null) {
                return null;
            }
            $this->assertDurableOrigin($locked, [
                'public_id' => (string) $owned['checkout_public_id'],
            ]);
            return $this->sessionRepository->claimCreate(
                (int) $locked['id'], $claimOwner, $now, $leaseExpiresAt
            );
        });
        if ($claimed === null) {
            $stored = $this->sessionRepository->findOwnedByPublicId($publicId, $owner)
                ?? throw new RecordNotFoundException('La sesion de pago no existe.');
            return $this->publicData($stored, (string) $stored['checkout_public_id'], true, true);
        }

        $version = (int) $claimed['create_version'];
        WebpayCreateRecovery::schedule((int) $claimed['id']);
        $origin = $this->originRepository->findByPaymentAttemptId($publicId)
            ?? throw new ConflictException('Falta la autoridad durable del intento.', 'state_conflict');
        $remoteStartedAt = current_time('mysql');
        if (! $this->sessionRepository->markCreateRemoteStarted(
            (int) $claimed['id'], $claimOwner, $version, $remoteStartedAt
        )) {
            $stored = $this->sessionRepository->findOwnedByPublicId($publicId, $owner)
                ?? throw new RecordNotFoundException('La sesion de pago no existe.');
            return $this->publicData($stored, (string) $stored['checkout_public_id'], true, true);
        }

        try {
            $result = $this->gateway->createSession(new PaymentSessionContext(
                $origin->paymentAttemptId(),
                $origin->originResourceId(),
                $origin->amountClp() . '.00',
                $origin->currency(),
                $origin->expiresAt(),
                (string) $claimed['idempotency_key'],
                $origin->buyOrder(),
                $origin->financialSessionId()
            ));
            if ($result->status !== GatewaySessionResult::STATUS_READY) {
                $status = $result->status === GatewaySessionResult::STATUS_REJECTED
                    ? PaymentSession::STATUS_CREATE_RETRYABLE
                    : PaymentSession::STATUS_CREATE_FAILED;
                $this->sessionRepository->finishCreateFailure(
                    (int) $claimed['id'], $claimOwner, $version, $status,
                    $result->errorCode ?? $result->status, current_time('mysql')
                );
            } else {
                $this->persistCreateResult(
                    $claimed, $origin, $claimOwner, $version, $result
                );
            }
        } catch (Throwable) {
            $this->sessionRepository->finishCreateFailure(
                (int) $claimed['id'], $claimOwner, $version,
                PaymentSession::STATUS_CREATE_AMBIGUOUS,
                'remote_result_ambiguous', current_time('mysql')
            );
        }

        $stored = $this->sessionRepository->findOwnedByPublicId($publicId, $owner)
            ?? throw new RecordNotFoundException('La sesion de pago no existe.');
        return $this->publicData($stored, (string) $stored['checkout_public_id'], $reused, true);
    }

    private function persistCreateResult(
        array $claimed,
        DurablePaymentOrigin $origin,
        string $owner,
        int $version,
        GatewaySessionResult $result
    ): void {
        if ($result->redirectUrl === null
            || ! WebpayPaymentGateway::isAllowedPaymentUrl(
                $origin->environment(), $result->redirectUrl
            )
            || $result->provider !== 'webpay_plus'
            || $result->expiresAt > $origin->expiresAt()) {
            throw new InvalidArgumentException('El resultado Webpay no corresponde al intento.');
        }
        $tokenHash = hash('sha256', $result->providerSessionId);
        $now = current_time('mysql');
        try {
            $saved = $this->checkoutRepository->transaction(function () use (
                $claimed, $origin, $owner, $version, $result, $tokenHash, $now
            ): bool {
                $originId = $this->originRepository->idByPaymentAttemptId(
                    $origin->paymentAttemptId()
                );
                if ($originId === null) {
                    throw new PersistenceException('Falta PaymentOriginContext durante CAS.');
                }
                $binding = $this->originRepository->bindTokenHash(
                    $originId, $origin->paymentAttemptId(), $tokenHash, $now
                );
                if (! $binding->bound()) {
                    throw new PersistenceException('El token remoto no pudo vincularse por CAS.');
                }
                if (! $this->sessionRepository->completeCreate(
                    (int) $claimed['id'], $owner, $version, $result->provider,
                    $result->providerSessionId, (string) $result->redirectUrl,
                    $result->expiresAt, $now
                )) {
                    throw new PersistenceException('El resultado remoto perdio el CAS.');
                }
                return true;
            });
        } catch (PersistenceException) {
            $saved = false;
        }
        if (! $saved) {
            $stored = $this->sessionRepository->find((int) $claimed['id']);
            if ($stored !== null && $stored['status'] === PaymentSession::STATUS_READY
                && is_string($stored['provider_session_id'] ?? null)
                && hash_equals(hash('sha256', $stored['provider_session_id']), $tokenHash)) {
                return;
            }
            $this->sessionRepository->finishCreateFailure(
                (int) $claimed['id'], $owner, $version,
                PaymentSession::STATUS_CREATE_AMBIGUOUS,
                'create_result_cas_conflict', $now
            );
        }
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

        $now = current_time('mysql');
        if (($session['status'] ?? null) === PaymentSession::STATUS_PENDING
            && (string) $session['expires_at'] <= $now) {
            $this->checkoutRepository->transaction(function () use (
                &$session,
                $now
            ): void {
                $locked = $this->sessionRepository->findForUpdate(
                    (int) $session['id']
                );
                if ($locked !== null) {
                    $this->sessionRepository->expirePending(
                        (int) $locked['id'],
                        $now
                    );
                    $session = $this->sessionRepository->find(
                        (int) $locked['id']
                    ) ?? $locked;
                }
            });
        }

        return $this->publicData(
            $session,
            (string) $session['checkout_public_id'],
            null,
            false
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

    private function assertOrderOwnership(
        array $checkout,
        array $orders,
        array $orderIds
    ): void {
        if (array_map('intval', array_column($orders, 'id')) !== $orderIds) {
            throw new InvalidArgumentException(
                'El Checkout no contiene el conjunto exacto de Orders.'
            );
        }
        foreach ($orders as $order) {
            $expectedCustomer = ($checkout['owner_type'] ?? null) === 'user'
                ? (int) $checkout['user_id']
                : 0;
            if ((int) $order['customer_id'] !== $expectedCustomer) {
                throw new InvalidArgumentException(
                    'Una Order no pertenece al owner del Checkout.'
                );
            }
        }
    }

    private function assertActiveReservations(array $orderIds): void
    {
        $now = current_time('mysql');
        $reservations = $this->reservationRepository
            ->findByOrderIdsForUpdate($orderIds);
        $covered = [];
        foreach ($reservations as $reservation) {
            if (($reservation['status'] ?? null) !== 'active'
                || (string) $reservation['expires_at'] <= $now) {
                throw new InvalidArgumentException(
                    'Las Orders no tienen reservas activas y vigentes.'
                );
            }
            $covered[(int) $reservation['order_id']] = true;
        }
        $coveredIds = array_keys($covered);
        sort($coveredIds, SORT_NUMERIC);
        if ($coveredIds !== $orderIds) {
            throw new InvalidArgumentException(
                'Las Orders no tienen reservas activas y vigentes.'
            );
        }
    }

    private function publicData(
        array $session,
        string $checkoutPublicId,
        ?bool $reused,
        bool $includeRedirectToken
    ): array {
        $data = [
            'payment_session_id' => (string) $session['public_id'],
            'checkout_id' => $checkoutPublicId,
            'status' => (string) $session['status'],
            'currency' => (string) $session['currency'],
            'amount' => (string) $session['amount'],
            'expires_at' => (string) $session['expires_at'],
            'created_at' => (string) $session['created_at'],
        ];

        if (is_string($session['provider'] ?? null)) {
            $data['provider'] = $session['provider'];
        }
        if (is_string($session['redirect_url'] ?? null)) {
            $data['redirect_url'] = $session['redirect_url'];
        }

        if ($includeRedirectToken) {
            $token = $this->redirectToken($session, $checkoutPublicId);

            if ($token !== null) {
                $data['token_ws'] = $token;
            }
        }

        if ($reused !== null) {
            $data['reused'] = $reused;
        }

        return $data;
    }

    private function redirectToken(
        array $session,
        string $checkoutPublicId
    ): ?string {
        $token = $session['provider_session_id'] ?? null;
        $url = $session['redirect_url'] ?? null;
        $expiresAt = $session['expires_at'] ?? null;

        if (
            ($session['status'] ?? null) !== PaymentSession::STATUS_READY
            || ($session['provider'] ?? null) !== 'webpay_plus'
            || ! is_string($token)
            || preg_match('/^[A-Za-z0-9]{16,191}$/D', $token) !== 1
            || ! is_string($expiresAt)
            || $expiresAt <= current_time('mysql')
        ) {
            return null;
        }

        $origin = $this->originRepository->findByPaymentAttemptId(
            (string) ($session['public_id'] ?? '')
        );

        if (
            $origin === null
            || $origin->paymentAttemptId() !== ($session['public_id'] ?? null)
            || $origin->originResourceId() !== $checkoutPublicId
            || $origin->gatewayId() !== 'webpay_plus'
            || $origin->expiresAt() < $expiresAt
            || $origin->tokenHash() === null
            || ! hash_equals($origin->tokenHash(), hash('sha256', $token))
            || ! WebpayPaymentGateway::isAllowedPaymentUrl(
                $origin->environment(),
                $url
            )
        ) {
            return null;
        }

        return $token;
    }

    private function localAttemptData(
        array $session,
        string $checkoutPublicId,
        bool $reused
    ): array {
        return [
            'payment_session_id' => (string) $session['public_id'],
            'checkout_id' => $checkoutPublicId,
            'status' => (string) $session['status'],
            'currency' => (string) $session['currency'],
            'amount' => (string) $session['amount'],
            'expires_at' => (string) $session['expires_at'],
            'created_at' => (string) $session['created_at'],
            'reused' => $reused,
            'next_action' => ($session['status'] ?? null)
                === PaymentSession::STATUS_PENDING
                    ? 'await_payment_provider_initialization'
                    : 'start_new_payment_attempt',
        ];
    }

    private function origin(
        array $checkout,
        string $paymentAttemptId,
        string $idempotencyKey,
        string $amount,
        string $now
    ): DurablePaymentOrigin {
        if (preg_match('/^([1-9]\d*)\.00$/D', $amount, $matches) !== 1) {
            throw new InvalidArgumentException('El monto CLP no es canonico.');
        }
        $configuration = PaymentGatewayConfiguration::webpay();
        $checkoutPublicId = (string) $checkout['public_id'];

        return new DurablePaymentOrigin(
            'poc_' . bin2hex(random_bytes(20)),
            WordPressSiteScope::current(),
            DurablePaymentOrigin::ORIGIN_VECIAHORRA,
            $checkoutPublicId,
            'webpay_plus',
            $paymentAttemptId,
            (int) $matches[1],
            $configuration->environment,
            hash('sha256', $configuration->commerceCode),
            WebpayTransactionReference::buyOrder(
                $checkoutPublicId,
                $idempotencyKey
            ),
            WebpayTransactionReference::sessionId($checkoutPublicId),
            null,
            1,
            $now,
            $now,
            (string) $checkout['expires_at']
        );
    }

    private function assertDurableOrigin(array $session, array $checkout): void
    {
        $origin = $this->originRepository->findByPaymentAttemptId(
            (string) $session['public_id']
        );
        $checkoutPublicId = (string) $checkout['public_id'];
        if ($origin === null
            || $origin->origin() !== DurablePaymentOrigin::ORIGIN_VECIAHORRA
            || ! hash_equals(
                $origin->originResourceId(),
                $checkoutPublicId
            )
            || $origin->paymentAttemptId() !== (string) $session['public_id']
            || $origin->gatewayId() !== 'webpay_plus'
            || $origin->currency() !== 'CLP'
            || $origin->amountClp() !== (int) substr(
                (string) $session['amount'], 0, -3
            )
            || $origin->expiresAt() !== (string) $session['expires_at']) {
            throw new ConflictException(
                'El intento local no posee autoridad durable coherente.',
                'state_conflict'
            );
        }
    }

}
