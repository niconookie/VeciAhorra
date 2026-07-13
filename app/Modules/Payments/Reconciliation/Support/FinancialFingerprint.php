<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Support;

use RuntimeException;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\FinancialFingerprintComponents;

final class FinancialFingerprint
{
    public const VERSION = 1;

    public static function canonicalJson(
        FinancialFingerprintComponents $components
    ): string {
        $json = json_encode(
            $components->canonicalData(),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        if (! is_string($json)) {
            throw new RuntimeException('No fue posible canonicalizar el resultado.');
        }

        return $json;
    }

    public static function make(
        FinancialFingerprintComponents $components
    ): string {
        return hash('sha256', self::canonicalJson($components));
    }

    private function __construct()
    {
    }
}
