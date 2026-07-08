<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class PaymentOrderSchema implements TableInterface
{
    public function name(): string
    {
        return 'payment_orders';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->bigIntegerUnsigned('payment_id')
            ->bigIntegerUnsigned('order_id')
            ->datetime('created_at')
            ->unique('order_id', 'payment_orders_order_unique')
            ->unique(
                ['payment_id', 'order_id'],
                'payment_orders_payment_order_unique'
            )
            ->index('payment_id', 'payment_orders_payment_id_index');
    }
}
