<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Support;

use RuntimeException;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;

final class PaymentOriginKey
{
    public const VERSION = 1;

    public static function make(DurablePaymentOrigin $origin): string
    {
        $json = json_encode([
            'site_scope' => $origin->siteScope(),
            'origin' => $origin->origin(),
            'origin_resource_id' => $origin->originResourceId(),
            'gateway_id' => $origin->gatewayId(),
            'payment_attempt_id' => $origin->paymentAttemptId(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (! is_string($json)) {
            throw new RuntimeException('No fue posible canonicalizar el origen.');
        }

        return hash('sha256', $json);
    }

    private function __construct()
    {
    }
}
