<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Schemas\PaymentSessionSchema;

final class CreatePaymentSessionsTable
{
    public function __construct(private ?string $tableName = null)
    {
    }

    public function up(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $schema = new PaymentSessionSchema();
        $name = $this->tableName
            ?? $wpdb->prefix . Config::TABLE_PREFIX . $schema->name();
        $builder = TableBuilder::make($name);
        $schema->define($builder);
        dbDelta($builder->build($wpdb->get_charset_collate()));
    }
}
