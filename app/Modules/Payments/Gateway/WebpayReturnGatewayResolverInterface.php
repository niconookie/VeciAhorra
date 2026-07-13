<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

interface WebpayReturnGatewayResolverInterface
{
    public function resolve(
        ?WebpayReturnContext $context
    ): WebpayReturnGatewayInterface;
}
