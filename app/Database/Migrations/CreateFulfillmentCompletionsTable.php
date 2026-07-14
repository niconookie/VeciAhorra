<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Schemas\FulfillmentCompletionSchema;

final class CreateFulfillmentCompletionsTable
{
    public function __construct(private ?string $tableName = null)
    {
    }

    public function up(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;
        $schema = new FulfillmentCompletionSchema();
        $builder = TableBuilder::make(
            $this->tableName ?? $wpdb->prefix . Config::TABLE_PREFIX . $schema->name()
        );
        $schema->define($builder);
        dbDelta($builder->build($wpdb->get_charset_collate()));
    }
}
