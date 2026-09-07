<?php
declare(strict_types=1);
namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;

/** Resumable additive migration. Historical tracking remains unattributed. */
final class AddCourierContinuity
{
    public function up(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . Config::TABLE_PREFIX;
        $columns = [
            'deliveries' => ['transition_version' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0'],
            'delivery_tracking' => [
                'transition_version' => 'BIGINT UNSIGNED NULL DEFAULT NULL',
                'actor_type' => 'VARCHAR(20) NULL DEFAULT NULL',
                'actor_id' => 'BIGINT UNSIGNED NULL DEFAULT NULL',
                'previous_status' => 'VARCHAR(20) NULL DEFAULT NULL',
                'new_status' => 'VARCHAR(20) NULL DEFAULT NULL',
                'previous_courier_id' => 'BIGINT UNSIGNED NULL DEFAULT NULL',
                'new_courier_id' => 'BIGINT UNSIGNED NULL DEFAULT NULL',
                'reason_code' => 'VARCHAR(40) NULL DEFAULT NULL',
                'reason' => 'VARCHAR(500) NULL DEFAULT NULL',
            ],
        ];
        foreach ($columns as $name => $definitions) {
            foreach ($definitions as $field => $definition) {
                $table = $prefix . $name;
                $column = $wpdb->get_row("SHOW COLUMNS FROM `{$table}` LIKE '{$field}'", ARRAY_A);
                if ($wpdb->last_error !== '') throw new PersistenceException('continuity_table_unavailable');
                if ($column === null) {
                    if ($wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$field}` {$definition}") === false) throw new PersistenceException('continuity_column_failed');
                    $column = $wpdb->get_row("SHOW COLUMNS FROM `{$table}` LIKE '{$field}'", ARRAY_A);
                }
                $type = strtolower(preg_replace('/ (?:NOT )?NULL.*/', '', $definition));
                $actual = preg_replace('/bigint\(\d+\)/', 'bigint', strtolower($column['Type'] ?? ''));
                if ($actual !== $type || ($column['Null'] ?? '') !== ($name === 'deliveries' ? 'NO' : 'YES')
                    || ($name === 'delivery_tracking' && $column['Default'] !== null)
                    || ($name === 'deliveries' && (string)$column['Default'] !== '0')) throw new PersistenceException('continuity_column_incompatible');
            }
        }
        $table = $prefix . 'delivery_tracking';
        $index = $wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name='delivery_tracking_version_unique'", ARRAY_A);
        if ($wpdb->last_error !== '') throw new PersistenceException('continuity_index_unavailable');
        if ($index === []) {
            if ($wpdb->query("ALTER TABLE `{$table}` ADD UNIQUE INDEX delivery_tracking_version_unique (delivery_id,transition_version)") === false) throw new PersistenceException('continuity_index_failed');
            $index = $wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name='delivery_tracking_version_unique'", ARRAY_A);
        }
        usort($index, static fn(array $a,array $b): int => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
        if (array_column($index,'Column_name') !== ['delivery_id','transition_version'] || array_sum(array_column($index,'Non_unique')) !== 0) throw new PersistenceException('continuity_index_incompatible');
    }
}
