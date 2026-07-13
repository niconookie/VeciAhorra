<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

use VeciAhorra\Modules\Payments\Models\Payment;
use VeciAhorra\Modules\Payments\Models\PaymentConfirmationResult;

final class DummyPaymentGateway implements
    PaymentConfirmationGatewayInterface,
    WebpayReturnGatewayInterface
{
    public function createPaymentSession(Payment $payment): array
    {
        $reference = 'DUMMY-' . strtoupper(substr(
            hash('sha256', $payment->paymentReference),
            0,
            8
        ));

        return [
            'provider' => $this->getProviderName(),
            'provider_reference' => $reference,
            'payment_url' => 'https://dummy.veciahorra/pay/' . $reference,
            'expires_at' => (new \DateTimeImmutable(
                $payment->createdAt,
                wp_timezone()
            ))
                ->modify('+15 minutes')
                ->format('Y-m-d\TH:i:s'),
        ];
    }

    public function getProviderName(): string
    {
        return 'dummy';
    }

    public function confirmPayment(
        string $providerReference
    ): PaymentConfirmationResult {
        return str_starts_with($providerReference, 'DUMMY-FAIL-')
            ? PaymentConfirmationResult::failed()
            : PaymentConfirmationResult::paid();
    }

    public function commit(string $token): WebpayCommitResult
    {
        throw new PaymentGatewayException(
            'El gateway Webpay no esta configurado.',
            'webpay_not_configured'
        );
    }
}
