<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

use VeciAhorra\Modules\Payments\Models\Payment;

interface PaymentGatewayInterface
{
    public function createPaymentSession(Payment $payment): array;

    public function getProviderName(): string;
}
