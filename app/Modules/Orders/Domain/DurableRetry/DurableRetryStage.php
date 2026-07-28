<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use InvalidArgumentException;

final class DurableRetryStage
{
    public const RECONCILIATION = 'reconciliation';
    public const BUSINESS_COMPLETION = 'business_completion';
    public const DELIVERY_COMPLETION = 'delivery_completion';
    public const FULFILLMENT_COMPLETION = 'fulfillment_completion';

    private const ALL = [
        self::RECONCILIATION,
        self::BUSINESS_COMPLETION,
        self::DELIVERY_COMPLETION,
        self::FULFILLMENT_COMPLETION,
    ];

    public static function all(): array
    {
        return self::ALL;
    }

    public static function assert(string $stage): void
    {
        if (! in_array($stage, self::ALL, true)) {
            throw new InvalidArgumentException('Invalid durable retry stage.');
        }
    }
}
