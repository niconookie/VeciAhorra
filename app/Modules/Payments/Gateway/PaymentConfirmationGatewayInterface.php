<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

use VeciAhorra\Modules\Payments\Models\PaymentConfirmationResult;

interface PaymentConfirmationGatewayInterface
{
    public function confirmPayment(
        string $providerReference
    ): PaymentConfirmationResult;
}
