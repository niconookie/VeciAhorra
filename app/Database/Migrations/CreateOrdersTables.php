<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;
use VeciAhorra\Database\Schemas\OrderItemSchema;
use VeciAhorra\Database\Schemas\OrderSchema;

/**
 * Crea o actualiza las tablas de pedidos de forma idempotente.
 */
final class CreateOrdersTables
{
    public function __construct(
        private ?string $ordersTableName = null,
        private ?string $orderItemsTableName = null
    ) {
    }

    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $this->create(
            new OrderSchema(),
            $this->ordersTableName
        );
        $this->create(
            new OrderItemSchema(),
            $this->orderItemsTableName
        );
    }

    private function create(
        TableInterface $schema,
        ?string $tableName
    ): void {
        global $wpdb;

        $physicalName = $tableName
            ?? $wpdb->prefix . Config::TABLE_PREFIX . $schema->name();
        $builder = TableBuilder::make($physicalName);

        $schema->define($builder);

        dbDelta(
            $builder->build($wpdb->get_charset_collate())
        );
    }
}
