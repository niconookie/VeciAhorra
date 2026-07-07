<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Schemas;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

final class CartItemSchema implements TableInterface
{
    public function name(): string
    {
        return 'cart_items';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->string('session_id', 64)->nullable()
            ->bigIntegerUnsigned('user_id')->nullable()
            ->bigIntegerUnsigned('inventory_id')
            ->bigIntegerUnsigned('product_id')
            ->bigIntegerUnsigned('minimarket_id')
            ->integerUnsigned('quantity')
            ->decimal('unit_price_snapshot', 10, 2)
            ->datetime('created_at')
            ->datetime('updated_at')
            ->unique(
                ['session_id', 'inventory_id'],
                'cart_items_session_inventory_unique'
            )
            ->unique(
                ['user_id', 'inventory_id'],
                'cart_items_user_inventory_unique'
            )
            ->index('session_id', 'cart_items_session_id_index')
            ->index('user_id', 'cart_items_user_id_index')
            ->index('inventory_id', 'cart_items_inventory_id_index')
            ->index('minimarket_id', 'cart_items_minimarket_id_index');
    }
}
