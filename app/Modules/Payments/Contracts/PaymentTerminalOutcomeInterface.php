<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Contracts;

interface PaymentTerminalOutcomeInterface
{
    public function cancel(int $paymentSessionId, string $checkoutPublicId): void;
}
