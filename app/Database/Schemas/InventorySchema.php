<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

/**
 * Define la estructura de la tabla de inventario del marketplace.
 */
final class InventorySchema implements TableInterface
{
    public function name(): string
    {
        return 'inventory';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->bigIntegerUnsigned('product_id')
            ->bigIntegerUnsigned('minimarket_id')
            ->decimal('price', 10, 2)
            ->integer('stock')
                ->default('0')
            ->string('status', 20)
                ->default('active')
            ->datetime('created_at')
            ->datetime('updated_at')
            ->unique(
                ['product_id', 'minimarket_id'],
                'inventory_product_minimarket_unique'
            )
            ->index('product_id', 'inventory_product_id_index')
            ->index('minimarket_id', 'inventory_minimarket_id_index')
            ->index('status', 'inventory_status_index');
    }
}
