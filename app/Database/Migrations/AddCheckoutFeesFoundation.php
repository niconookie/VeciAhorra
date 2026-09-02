<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Schemas\CheckoutRefundSchema;
use VeciAhorra\Database\Schemas\CheckoutSchema;
use VeciAhorra\Database\Schemas\InventorySchema;
use VeciAhorra\Database\Tables\ProductsTable;
use VeciAhorra\Database\Tables\StoresTable;

final class AddCheckoutFeesFoundation
{
    public function up(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ([new CheckoutSchema(), new CheckoutRefundSchema(), new InventorySchema(), new ProductsTable(), new StoresTable()] as $schema) {
            $builder = TableBuilder::make($wpdb->prefix . Config::TABLE_PREFIX . $schema->name());
            dbDelta($builder->build($wpdb->get_charset_collate()));
        }
        $required = [
            'checkouts' => ['product_subtotal', 'platform_fee', 'delivery_fee', 'fee_policy_version'],
            'checkout_refunds' => ['checkout_id', 'idempotency_key', 'product_refund', 'platform_fee_refund', 'delivery_fee_refund', 'total_refund'],
            'inventory' => ['delivery_enabled'],
            'products' => ['delivery_enabled'],
            'stores' => ['delivery_enabled'],
        ];
        foreach ($required as $name => $columns) {
            $table = $wpdb->prefix . Config::TABLE_PREFIX . $name;
            $actual = $wpdb->get_col('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            if (array_diff($columns, array_map('strval', is_array($actual) ? $actual : [])) !== []) {
                throw new \RuntimeException('La migracion de checkout fees no materializo el esquema requerido.');
            }
        }
    }
}
