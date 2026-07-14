<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Migrations;

use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;

/** Verifica las claves idempotentes de FulfillmentCompletion sin alterar datos. */
final class EnsureUniqueFulfillmentCompletion
{
    /** @var array<string,string> */
    private const INDEXES = [
        'business_completion_id' => 'fulfillment_completions_business_explicit_unique',
        'idempotency_key' => 'fulfillment_completions_key_explicit_unique',
    ];

    public function __construct(private ?string $tableName = null)
    {
    }

    public function up(): void
    {
        global $wpdb;

        $table = $this->tableName
            ?? $wpdb->prefix . Config::TABLE_PREFIX . 'fulfillment_completions';

        // Se auditan ambos campos antes de cualquier DDL para no dejar una
        // migracion parcialmente aplicada frente a datos incompatibles.
        foreach (array_keys(self::INDEXES) as $column) {
            $duplicate = $wpdb->get_row(sprintf(
                'SELECT `%s`, COUNT(*) AS row_count FROM `%s`'
                . ' GROUP BY `%s` HAVING COUNT(*) > 1 LIMIT 1',
                esc_sql($column), esc_sql($table), esc_sql($column)
            ), ARRAY_A);
            if ($wpdb->last_error !== '') {
                throw new PersistenceException('No fue posible auditar la unicidad de FulfillmentCompletion.');
            }
            if ($duplicate !== null) {
                throw new PersistenceException(sprintf(
                    'No se puede crear la unicidad de fulfillment_completions.%s: existen filas duplicadas.',
                    $column
                ));
            }
        }

        foreach (self::INDEXES as $column => $indexName) {
            if ($this->hasExactUniqueIndex($table, $column)) {
                continue;
            }
            $created = $wpdb->query(sprintf(
                'ALTER TABLE `%s` ADD UNIQUE KEY `%s` (`%s`)',
                esc_sql($table), esc_sql($indexName), esc_sql($column)
            ));
            if ($created === false || ! $this->hasExactUniqueIndex($table, $column)) {
                throw new PersistenceException(sprintf(
                    'No fue posible verificar la unicidad de fulfillment_completions.%s.',
                    $column
                ));
            }
        }

        foreach (array_keys(self::INDEXES) as $column) {
            if (! $this->hasExactUniqueIndex($table, $column)) {
                throw new PersistenceException('Las restricciones FulfillmentCompletion no quedaron instaladas.');
            }
        }
    }

    private function hasExactUniqueIndex(string $table, string $requiredColumn): bool
    {
        global $wpdb;

        $indexes = $wpdb->get_results(sprintf('SHOW INDEX FROM `%s`', esc_sql($table)), ARRAY_A);
        if ($wpdb->last_error !== '') {
            throw new PersistenceException('No fue posible inspeccionar los indices FulfillmentCompletion.');
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
            if (array_values($columns) === [$requiredColumn]) {
                return true;
            }
        }
        return false;
    }
}
