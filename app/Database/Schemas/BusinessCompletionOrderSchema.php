<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class BusinessCompletionOrderSchema implements TableInterface
{
    public function name(): string { return 'business_completion_orders'; }

    public function define(TableBuilder $table): void
    {
        $table->id()
            ->bigIntegerUnsigned('business_completion_id')
            ->bigIntegerUnsigned('order_id')
            ->datetime('created_at')
            ->unique(['business_completion_id', 'order_id'], 'business_completion_orders_pair_unique')
            ->unique('order_id', 'business_completion_orders_order_unique')
            ->index('business_completion_id', 'business_completion_orders_completion_index');
    }
}
