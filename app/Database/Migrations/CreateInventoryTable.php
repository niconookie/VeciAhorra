<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Schemas\InventorySchema;

/**
 * Crea o actualiza la tabla de inventario de forma idempotente.
 */
final class CreateInventoryTable
{
    public function __construct(
        private ?string $tableName = null
    ) {
    }

    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $schema = new InventorySchema();
        $tableName = $this->tableName
            ?? $wpdb->prefix . Config::TABLE_PREFIX . $schema->name();
        $builder = TableBuilder::make($tableName);

        $schema->define($builder);

        dbDelta(
            $builder->build($wpdb->get_charset_collate())
        );
    }
}
