<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

/**
 * Define la estructura base de la tabla de entregas.
 */
final class DeliverySchema implements TableInterface
{
    public function name(): string
    {
        return 'deliveries';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->bigIntegerUnsigned('order_id')
            ->bigIntegerUnsigned('customer_id')
            ->bigIntegerUnsigned('minimarket_id')
            ->bigIntegerUnsigned('courier_id')
                ->nullable()
            ->string('status', 20)
                ->default('pending')
            ->datetime('created_at')
            ->datetime('updated_at')
            ->index('order_id', 'deliveries_order_id_index')
            ->index('customer_id', 'deliveries_customer_id_index')
            ->index('minimarket_id', 'deliveries_minimarket_id_index')
            ->index('courier_id', 'deliveries_courier_id_index')
            ->index('status', 'deliveries_status_index');
    }
}
