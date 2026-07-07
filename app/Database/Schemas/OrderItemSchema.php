<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

/**
 * Define la estructura de los items y sus precios congelados.
 */
final class OrderItemSchema implements TableInterface
{
    public function name(): string
    {
        return 'order_items';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->bigIntegerUnsigned('order_id')
            ->bigIntegerUnsigned('product_id')
            ->bigIntegerUnsigned('inventory_id')
            ->integerUnsigned('quantity')
            ->decimal('unit_price', 10, 2)
            ->decimal('subtotal', 10, 2)
            ->datetime('created_at')
            ->datetime('updated_at')
            ->index('order_id', 'order_items_order_id_index')
            ->index('product_id', 'order_items_product_id_index')
            ->index('inventory_id', 'order_items_inventory_id_index');
    }
}
