<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;
use VeciAhorra\Database\Tables\StoreDecisionHistoryTable;
use VeciAhorra\Database\Tables\ZonalAdminServiceZonesTable;

final class CreateZonalAdminFoundationTables
{
    public function up(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $this->create(new ZonalAdminServiceZonesTable());
        $this->create(new StoreDecisionHistoryTable());
    }

    private function create(TableInterface $schema): void
    {
        global $wpdb;
        $builder = TableBuilder::make($wpdb->prefix . Config::TABLE_PREFIX . $schema->name());
        $schema->define($builder);
        dbDelta($builder->build($wpdb->get_charset_collate()));
    }
}
