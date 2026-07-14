<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\WooCommerce;

use InvalidArgumentException;

final class WooCommercePaymentCompletionResult
{
    public const APPLIED_NOW = 'wc_applied_now';
    public const ALREADY_APPLIED_SAME_PAYMENT = 'wc_already_applied_same_payment';
    public const ORDER_NOT_FOUND = 'wc_order_not_found';
    public const ORDER_MISMATCH = 'wc_order_mismatch';
    public const AMOUNT_MISMATCH = 'wc_amount_mismatch';
    public const GATEWAY_MISMATCH = 'wc_gateway_mismatch';
    public const PAYMENT_ALREADY_DIFFERENT = 'wc_payment_already_different';
    public const PAYMENT_COMPLETION_FAILED = 'wc_payment_completion_failed';
    public const PAYMENT_RESULT_UNVERIFIED = 'wc_payment_result_unverified';

    public static function isValid(string $result): bool
    {
        return in_array($result, [
            self::APPLIED_NOW,
            self::ALREADY_APPLIED_SAME_PAYMENT,
            self::ORDER_NOT_FOUND,
            self::ORDER_MISMATCH,
            self::AMOUNT_MISMATCH,
            self::GATEWAY_MISMATCH,
            self::PAYMENT_ALREADY_DIFFERENT,
            self::PAYMENT_COMPLETION_FAILED,
            self::PAYMENT_RESULT_UNVERIFIED,
        ], true);
    }

    public static function assert(string $result): void
    {
        if (! self::isValid($result)) {
            throw new InvalidArgumentException(
                'El resultado de completitud WooCommerce no es valido.'
            );
        }
    }

    private function __construct()
    {
    }
}
