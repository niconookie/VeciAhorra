<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class CheckoutOrderSchema implements TableInterface
{
    public function name(): string
    {
        return 'checkout_orders';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->bigIntegerUnsigned('checkout_id')
            ->bigIntegerUnsigned('order_id')
            ->datetime('created_at')
            ->unique('order_id', 'checkout_orders_order_unique')
            ->unique(
                ['checkout_id', 'order_id'],
                'checkout_orders_checkout_order_unique'
            )
            ->index('checkout_id', 'checkout_orders_checkout_id_index');
    }
}
