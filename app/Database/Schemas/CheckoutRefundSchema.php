<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class CheckoutRefundSchema implements TableInterface
{
    public function name(): string { return 'checkout_refunds'; }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->bigIntegerUnsigned('checkout_id')
            ->string('idempotency_key', 128)
            ->decimal('product_refund', 10, 2)
            ->decimal('platform_fee_refund', 10, 2)->default('0.00')
            ->decimal('delivery_fee_refund', 10, 2)->default('0.00')
            ->decimal('total_refund', 10, 2)
            ->string('status', 30)->default('recorded')
            ->datetime('created_at')
            ->unique(['checkout_id', 'idempotency_key'], 'checkout_refunds_key_unique')
            ->index('checkout_id', 'checkout_refunds_checkout_index');
    }
}
