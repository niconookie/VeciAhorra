<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Payments\Gateway;
interface RefundGatewayInterface
{
    /** Returns a closed, secret-free result. Exceptions never authorize a retry. */
    public function refund(#[\SensitiveParameter] string $token, int $amount): RefundResult;
}
