<?php
declare(strict_types=1);
namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;

/** Additive and resumable: historical rows retain NULL. */
final class AddCourierTerritory
{
    public function up(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . Config::TABLE_PREFIX;
        foreach (['couriers', 'checkouts', 'orders', 'deliveries'] as $name) {
            $table = $prefix . $name;
            $column = $wpdb->get_row("SHOW COLUMNS FROM `{$table}` LIKE 'service_zone_id'", ARRAY_A);
            if ($wpdb->last_error !== '') throw new PersistenceException('territory_table_unavailable:' . $name);
            if ($column === null) {
                if ($wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `service_zone_id` BIGINT UNSIGNED NULL DEFAULT NULL") === false) {
                    throw new PersistenceException('territory_column_failed:' . $name);
                }
                $column = $wpdb->get_row("SHOW COLUMNS FROM `{$table}` LIKE 'service_zone_id'", ARRAY_A);
            }
            if (!is_array($column) || !preg_match('/^bigint(?:\(\d+\))? unsigned$/', strtolower($column['Type']))
                || $column['Null'] !== 'YES' || $column['Default'] !== null) {
                throw new PersistenceException('territory_column_incompatible:' . $name);
            }
        }
        $table = $prefix . 'deliveries';
        $index = $wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name='deliveries_zone_available_index'", ARRAY_A);
        if ($wpdb->last_error !== '') throw new PersistenceException('territory_index_unavailable');
        if ($index === []) {
            if ($wpdb->query("ALTER TABLE `{$table}` ADD INDEX `deliveries_zone_available_index` (`service_zone_id`,`status`,`courier_id`)") === false) {
                throw new PersistenceException('territory_index_failed');
            }
            $index = $wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name='deliveries_zone_available_index'", ARRAY_A);
        }
        usort($index, static fn(array $a, array $b): int => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
        if (array_column($index, 'Column_name') !== ['service_zone_id', 'status', 'courier_id']) {
            throw new PersistenceException('territory_index_incompatible');
        }
    }
}
