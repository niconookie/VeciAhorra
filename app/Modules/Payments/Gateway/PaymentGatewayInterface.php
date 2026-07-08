<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

use VeciAhorra\Modules\Payments\Models\Payment;
use VeciAhorra\Modules\Payments\Models\PaymentConfirmationResult;

interface PaymentGatewayInterface
{
    public function createPaymentSession(Payment $payment): array;

    public function confirmPayment(
        string $providerReference
    ): PaymentConfirmationResult;

    public function getProviderName(): string;
}
