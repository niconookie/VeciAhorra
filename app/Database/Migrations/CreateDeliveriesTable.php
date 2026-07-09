<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;
use VeciAhorra\Database\Schemas\DeliverySchema;

/**
 * Crea o actualiza la tabla base de entregas de forma idempotente.
 */
final class CreateDeliveriesTable
{
    public function __construct(
        private ?string $deliveriesTableName = null
    ) {
    }

    public function up(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $this->create(new DeliverySchema(), $this->deliveriesTableName);
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

        dbDelta($builder->build($wpdb->get_charset_collate()));
    }
}
