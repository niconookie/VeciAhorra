<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Schemas\PaymentSessionSchema;
use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Exceptions\PersistenceException;

final class AddDurableWebpayCreateState
{
    public function __construct(private ?string $tableName = null) {}

    public function up(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $schema = new PaymentSessionSchema();
        $name = $this->tableName ?? $wpdb->prefix . Config::TABLE_PREFIX . $schema->name();
        $builder = TableBuilder::make($name);
        $schema->define($builder);
        dbDelta($builder->build($wpdb->get_charset_collate()));

        foreach (['create_owner', 'create_version', 'create_lease_expires_at',
            'create_started_at', 'create_remote_started_at',
            'create_attempt_count', 'create_last_result'] as $column) {
            $found = $wpdb->get_var($wpdb->prepare(
                "SHOW COLUMNS FROM `{$name}` LIKE %s",
                $column
            ));
            if ($found !== $column) {
                throw new PersistenceException('La migracion durable de Webpay create quedo incompleta.');
            }
        }
    }
}
