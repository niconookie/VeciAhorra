<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Support;

final class WooCommerceTransactionReferenceFactory
{
    public const VERSION = 1;
    private const PREFIX = 'va-wp-v1-';

    public static function fromFinancialFingerprint(string $fingerprint): string
    {
        ReconciliationValidation::hash($fingerprint, 'financial_fingerprint');

        return self::PREFIX . $fingerprint;
    }

    private function __construct()
    {
    }
}
