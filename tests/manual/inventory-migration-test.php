<?php

declare(strict_types=1);

use VeciAhorra\Database\Migrations\CreateInventoryTable;

require_once dirname(__DIR__, 5) . '/wp-load.php';

global $wpdb;

$table = $wpdb->prefix . 'va_test_inventory_' . uniqid();
$migration = new CreateInventoryTable($table);

try {
    $migration->up();
    $migration->up();

    $columns = $wpdb->get_results(
        "SHOW COLUMNS FROM {$table}",
        ARRAY_A
    );
    $columnsByName = array_column($columns, null, 'Field');

    foreach (
        [
            'id',
            'product_id',
            'minimarket_id',
            'price',
            'stock',
            'status',
            'created_at',
            'updated_at',
        ] as $column
    ) {
        if (! isset($columnsByName[$column])) {
            throw new RuntimeException(
                "Falta la columna {$column}."
            );
        }
    }

    if ($columnsByName['price']['Type'] !== 'decimal(10,2)') {
        throw new RuntimeException('El tipo de price no es DECIMAL(10,2).');
    }

    if ($columnsByName['stock']['Default'] !== '0') {
        throw new RuntimeException('El default de stock no es 0.');
    }

    if ($columnsByName['status']['Default'] !== 'active') {
        throw new RuntimeException('El default de status no es active.');
    }

    $indexes = $wpdb->get_results(
        "SHOW INDEX FROM {$table}",
        ARRAY_A
    );
    $indexNames = array_values(array_unique(array_column(
        $indexes,
        'Key_name'
    )));

    foreach (
        [
            'PRIMARY',
            'inventory_product_minimarket_unique',
            'inventory_product_id_index',
            'inventory_minimarket_id_index',
            'inventory_status_index',
        ] as $index
    ) {
        if (! in_array($index, $indexNames, true)) {
            throw new RuntimeException(
                "Falta el índice {$index}."
            );
        }
    }

    $uniqueRows = array_values(array_filter(
        $indexes,
        static fn (array $index): bool =>
            $index['Key_name'] === 'inventory_product_minimarket_unique'
    ));
    usort(
        $uniqueRows,
        static fn (array $first, array $second): int =>
            (int) $first['Seq_in_index'] <=> (int) $second['Seq_in_index']
    );

    if (
        array_column($uniqueRows, 'Column_name')
        !== ['product_id', 'minimarket_id']
        || array_unique(array_column($uniqueRows, 'Non_unique')) !== ['0']
    ) {
        throw new RuntimeException(
            'El índice único de producto y minimarket no es válido.'
        );
    }

    echo "PASS inventory-migration-test\n";
} finally {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}
