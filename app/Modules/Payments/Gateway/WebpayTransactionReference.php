<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

final class WebpayTransactionReference
{
    public static function buyOrder(
        string $checkoutId,
        string $idempotencyKey
    ): string {
        return 'VA' . strtoupper(substr(hash(
            'sha256',
            $checkoutId . '|' . $idempotencyKey
        ), 0, 24));
    }

    public static function sessionId(string $checkoutId): string
    {
        return 'VA-' . strtoupper(substr(hash('sha256', $checkoutId), 0, 58));
    }
}
