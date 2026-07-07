<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class ReservationSchema implements TableInterface
{
    public function name(): string
    {
        return 'reservations';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->bigIntegerUnsigned('order_id')
            ->bigIntegerUnsigned('inventory_id')
            ->bigIntegerUnsigned('product_id')
            ->bigIntegerUnsigned('minimarket_id')
            ->integerUnsigned('quantity')
            ->string('status', 20)->default('active')
            ->datetime('reserved_at')
            ->datetime('expires_at')
            ->datetime('released_at')->nullable()
            ->datetime('created_at')
            ->datetime('updated_at')
            ->index('order_id', 'reservations_order_id_index')
            ->index('inventory_id', 'reservations_inventory_id_index')
            ->index('product_id', 'reservations_product_id_index')
            ->index('minimarket_id', 'reservations_minimarket_id_index')
            ->index('status', 'reservations_status_index')
            ->index('expires_at', 'reservations_expires_at_index');
    }
}
