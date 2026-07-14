<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;

/** Garantiza una sola Delivery durable por Order sin alterar datos existentes. */
final class EnsureUniqueDeliveryOrder
{
    public function __construct(private ?string $deliveriesTableName = null)
    {
    }

    public function up(): void
    {
        global $wpdb;

        $table = $this->deliveriesTableName
            ?? $wpdb->prefix . Config::TABLE_PREFIX . 'deliveries';
        $duplicate = $wpdb->get_row(sprintf(
            'SELECT order_id, COUNT(*) AS row_count FROM `%s`'
            . ' GROUP BY order_id HAVING COUNT(*) > 1 LIMIT 1',
            esc_sql($table)
        ), ARRAY_A);

        if ($wpdb->last_error !== '') {
            throw new PersistenceException('No fue posible auditar deliveries.order_id.');
        }
        if ($duplicate !== null) {
            throw new PersistenceException(
                'No se puede crear la unicidad de deliveries.order_id: existen filas duplicadas.'
            );
        }
        if ($this->hasUniqueOrderIndex($table)) {
            return;
        }

        $result = $wpdb->query(sprintf(
            'ALTER TABLE `%s` ADD UNIQUE KEY `deliveries_order_id_unique` (`order_id`)',
            esc_sql($table)
        ));
        if ($result === false || ! $this->hasUniqueOrderIndex($table)) {
            throw new PersistenceException('No fue posible verificar la unicidad de deliveries.order_id.');
        }
    }

    private function hasUniqueOrderIndex(string $table): bool
    {
        global $wpdb;

        $indexes = $wpdb->get_results(sprintf('SHOW INDEX FROM `%s`', esc_sql($table)), ARRAY_A);
        if ($wpdb->last_error !== '') {
            throw new PersistenceException('No fue posible inspeccionar los indices de deliveries.');
        }

        $columnsByIndex = [];
        foreach ($indexes as $index) {
            if ((int) $index['Non_unique'] !== 0) {
                continue;
            }
            $columnsByIndex[(string) $index['Key_name']][(int) $index['Seq_in_index']] = (string) $index['Column_name'];
        }
        foreach ($columnsByIndex as $columns) {
            ksort($columns);
            if (array_values($columns) === ['order_id']) {
                return true;
            }
        }
        return false;
    }
}
