<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayInterface;
use VeciAhorra\Modules\Payments\Models\Payment;
use VeciAhorra\Modules\Payments\Repository\PaymentRepository;

final class PaymentSessionService
{
    public function __construct(
        private PaymentRepository $repository,
        private PaymentGatewayInterface $gateway
    ) {
    }

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

        $session = $this->gateway->createPaymentSession($payment);
        $provider = $this->gateway->getProviderName();
        $providerReference = $session['provider_reference'] ?? null;
        $paymentUrl = $session['payment_url'] ?? null;
        $expiresAt = $session['expires_at'] ?? null;

        if (
            ($session['provider'] ?? null) !== $provider
            || ! is_string($providerReference)
            || trim($providerReference) === ''
            || ! is_string($paymentUrl)
            || filter_var($paymentUrl, FILTER_VALIDATE_URL) === false
            || ! is_string($expiresAt)
        ) {
            throw new InvalidArgumentException(
                'El proveedor devolvio una sesion de pago invalida.'
            );
        }

        $expiration = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s',
            $expiresAt,
            wp_timezone()
        );

        if ($expiration === false) {
            throw new InvalidArgumentException(
                'El proveedor devolvio una expiracion invalida.'
            );
        }

        $this->repository->updateSessionData(
            $payment->id,
            $provider,
            $providerReference,
            $expiration->format('Y-m-d H:i:s'),
            current_time('mysql')
        );

        return [
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'provider' => $provider,
            'provider_reference' => $providerReference,
            'payment_url' => $paymentUrl,
            'expires_at' => $expiresAt,
        ];
    }
}
