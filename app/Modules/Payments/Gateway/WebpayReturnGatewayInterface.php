<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

interface WebpayReturnGatewayInterface
{
    public function commit(string $token): WebpayCommitResult;
}
