<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

/**
 * Define la estructura de la tabla principal de pedidos.
 */
final class OrderSchema implements TableInterface
{
    public function name(): string
    {
        return 'orders';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->bigIntegerUnsigned('customer_id')
            ->bigIntegerUnsigned('minimarket_id')
            ->decimal('total', 10, 2)
                ->default('0.00')
            ->string('status', 20)
                ->default('reserved')
            ->string('store_fulfillment_status', 30)
                ->default('awaiting_confirmation')
            ->datetime('store_confirmed_at')->nullable()
            ->datetime('store_preparation_started_at')->nullable()
            ->datetime('store_ready_for_pickup_at')->nullable()
            ->datetime('reservation_expires_at')
                ->nullable()
            ->datetime('created_at')
            ->datetime('updated_at')
            ->index('customer_id', 'orders_customer_id_index')
            ->index('minimarket_id', 'orders_minimarket_id_index')
            ->index('status', 'orders_status_index')
            ->index('store_fulfillment_status', 'orders_store_fulfillment_status_index')
            ->index(
                'reservation_expires_at',
                'orders_reservation_expires_at_index'
            );
    }
}
