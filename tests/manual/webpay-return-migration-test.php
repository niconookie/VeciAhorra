<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\MigrationManager;
use VeciAhorra\Database\Migrations\CreateWebpayReturnsTable;

require_once dirname(__DIR__, 5) . '/wp-load.php';

global $wpdb;

$table = $wpdb->prefix . 'va_test_webpay_returns_' . uniqid();
$migration = new CreateWebpayReturnsTable($table);

try {
    $migration->up();
    $migration->up();
    $columns = array_column(
        $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A),
        null,
        'Field'
    );

    foreach ([
        'id', 'token_hash', 'payment_session_id', 'flow',
        'processing_status', 'result_status', 'result_json',
        'created_at', 'updated_at',
    ] as $column) {
        if (! isset($columns[$column])) {
            throw new RuntimeException("Falta la columna {$column}.");
        }
    }

    $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
    $tokenIndex = array_values(array_filter(
        $indexes,
        static fn (array $index): bool =>
            $index['Key_name'] === 'webpay_returns_token_hash_unique'
    ));

    if (
        count($tokenIndex) !== 1
        || (string) $tokenIndex[0]['Non_unique'] !== '0'
        || $tokenIndex[0]['Column_name'] !== 'token_hash'
    ) {
        throw new RuntimeException('token_hash no posee indice unico.');
    }

    $method = new ReflectionMethod(MigrationManager::class, 'migrations');
    $method->setAccessible(true);
    $registered = array_filter(
        $method->invoke(null),
        static fn (object $item): bool =>
            $item instanceof CreateWebpayReturnsTable
    );

    if (
        count($registered) !== 1
        || version_compare(Config::SCHEMA_VERSION, '0.14.0', '<')
    ) {
        throw new RuntimeException('La migracion no esta registrada correctamente.');
    }

    echo "PASS webpay-return-migration-test\n";
} finally {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}
