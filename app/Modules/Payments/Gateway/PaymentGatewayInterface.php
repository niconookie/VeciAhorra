<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

interface PaymentGatewayInterface
{
    public function createSession(
        PaymentSessionContext $context
    ): GatewaySessionResult;

    public function recoverSession(
        string $providerSessionId
    ): GatewaySessionResult;
}
