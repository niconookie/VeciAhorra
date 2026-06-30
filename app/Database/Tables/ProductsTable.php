<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Tables;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

/**
 * Define la estructura de la tabla de productos.
 */
final class ProductsTable implements TableInterface
{
    public function name(): string
    {
        return 'products';
    }

    public function define(TableBuilder $table): void
    {
        $table
            ->id()
            ->bigIntegerUnsigned('woo_product_id')
                ->nullable()
            ->string('name', 180)
            ->string('slug', 200)
            ->string('sku', 100)
                ->nullable()
            ->text('description')
                ->nullable()
            ->bigIntegerUnsigned('category_id')
                ->nullable()
            ->bigIntegerUnsigned('brand_id')
                ->nullable()
            ->bigIntegerUnsigned('unit_id')
                ->nullable()
            ->bigIntegerUnsigned('image_id')
                ->nullable()
            ->string('status', 20)
                ->default('draft')
            ->datetime('created_at')
            ->datetime('updated_at')
            ->unique('slug', 'products_slug_unique')
            ->unique('sku', 'products_sku_unique')
            ->unique(
                'woo_product_id',
                'products_woo_product_id_unique'
            )
            ->index('status', 'products_status_index')
            ->index('category_id', 'products_category_id_index')
            ->index('brand_id', 'products_brand_id_index')
            ->index('unit_id', 'products_unit_id_index')
            ->index('image_id', 'products_image_id_index')
            ->index('name', 'products_name_index');
    }
}
