<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Schemas\BusinessCompletionSchema;
use VeciAhorra\Database\Schemas\BusinessCompletionOrderSchema;

final class CreateBusinessCompletionsTable
{
    public function up(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;
        foreach ([new BusinessCompletionSchema(), new BusinessCompletionOrderSchema()] as $schema) {
            $builder = TableBuilder::make($wpdb->prefix . Config::TABLE_PREFIX . $schema->name());
            $schema->define($builder);
            dbDelta($builder->build($wpdb->get_charset_collate()));
        }
    }
}
