<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Support;

final class WebpayTokenReference
{
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function masked(string $token): string
    {
        return 'sha256:' . substr(self::hash($token), 0, 12);
    }
}
