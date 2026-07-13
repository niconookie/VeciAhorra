<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\MigrationManager;
use VeciAhorra\Database\Migrations\CreatePaymentConfirmationAuditsTable;
use VeciAhorra\Database\Migrations\CreatePaymentSessionsTable;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertConfirmationMigration(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

global $wpdb;

$suffix = bin2hex(random_bytes(5));
$sessionsTable = $wpdb->prefix . 'va_test_confirmation_sessions_' . $suffix;
$auditsTable = $wpdb->prefix . 'va_test_confirmation_audits_' . $suffix;
$collation = $wpdb->get_charset_collate();

try {
    $created = $wpdb->query("CREATE TABLE {$sessionsTable} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        public_id VARCHAR(64) NOT NULL,
        checkout_id BIGINT UNSIGNED NOT NULL,
        idempotency_key VARCHAR(128) NOT NULL,
        request_fingerprint VARCHAR(64) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        provider VARCHAR(50) NULL,
        provider_session_id VARCHAR(191) NULL,
        redirect_url TEXT NULL,
        currency VARCHAR(3) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        metadata TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY payment_sessions_public_id_unique (public_id),
        UNIQUE KEY payment_sessions_checkout_key_unique
            (checkout_id, idempotency_key)
    ) ENGINE=InnoDB {$collation}");
    assertConfirmationMigration($created !== false, 'No se creo fixture legacy.');
    $now = current_time('mysql');
    $wpdb->insert($sessionsTable, [
        'public_id' => 'ps_' . str_repeat('L', 43),
        'checkout_id' => 1001,
        'idempotency_key' => 'legacy-key-00000001',
        'request_fingerprint' => str_repeat('a', 64),
        'status' => 'ready',
        'currency' => 'CLP',
        'amount' => '1000.00',
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);

    $sessionMigration = new CreatePaymentSessionsTable($sessionsTable);
    $sessionMigration->up();
    $sessionMigration->up();
    $auditMigration = new CreatePaymentConfirmationAuditsTable($auditsTable);
    $auditMigration->up();
    $auditMigration->up();
    $columns = array_column(
        $wpdb->get_results("SHOW COLUMNS FROM {$sessionsTable}", ARRAY_A),
        null,
        'Field'
    );

    foreach ([
        'payment_id', 'confirmation_fingerprint',
        'confirmation_fingerprint_version', 'safe_financial_reference',
        'confirmed_at',
    ] as $column) {
        assertConfirmationMigration(
            isset($columns[$column]),
            "Falta payment_sessions.{$column}."
        );
        assertConfirmationMigration(
            $columns[$column]['Null'] === 'YES',
            "{$column} no permite historicos NULL."
        );
    }

    $legacy = $wpdb->get_row(
        "SELECT * FROM {$sessionsTable} WHERE checkout_id = 1001",
        ARRAY_A
    );
    assertConfirmationMigration(
        $legacy !== null
        && $legacy['payment_id'] === null
        && $legacy['confirmed_at'] === null,
        'La migracion invento una relacion historica.'
    );
    $indexes = $wpdb->get_results("SHOW INDEX FROM {$sessionsTable}", ARRAY_A);
    $indexNames = array_unique(array_column($indexes, 'Key_name'));

    foreach ([
        'payment_sessions_payment_id_unique',
        'payment_sessions_confirmation_fingerprint_index',
    ] as $index) {
        assertConfirmationMigration(
            in_array($index, $indexNames, true),
            "Falta el indice {$index}."
        );
    }

    $base = [
        'checkout_id' => 2001,
        'idempotency_key' => 'new-key-000000000001',
        'request_fingerprint' => str_repeat('b', 64),
        'status' => 'pending',
        'currency' => 'CLP',
        'amount' => '1000.00',
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        'payment_id' => 9001,
    ];
    assertConfirmationMigration(
        $wpdb->insert($sessionsTable, [
            ...$base,
            'public_id' => 'ps_' . str_repeat('A', 43),
        ]) === 1,
        'No se inserto relacion unica.'
    );
    $wpdb->suppress_errors(true);
    $duplicate = $wpdb->insert($sessionsTable, [
        ...$base,
        'public_id' => 'ps_' . str_repeat('B', 43),
        'checkout_id' => 2002,
        'idempotency_key' => 'new-key-000000000002',
    ]);
    $wpdb->suppress_errors(false);
    assertConfirmationMigration(
        $duplicate === false,
        'Dos sesiones aceptaron el mismo Payment.'
    );

    $auditColumns = array_column(
        $wpdb->get_results("SHOW COLUMNS FROM {$auditsTable}", ARRAY_A),
        null,
        'Field'
    );

    foreach ([
        'correlation_id', 'event_type', 'event_key',
        'payment_session_id', 'payment_id',
        'checkout_id', 'confirmation_fingerprint',
        'confirmation_fingerprint_version', 'provider', 'amount', 'currency',
        'previous_state', 'resulting_state', 'result_code', 'severity',
        'attempt_number', 'safe_financial_reference', 'order_ids_json',
        'context_json', 'created_at',
    ] as $column) {
        assertConfirmationMigration(
            isset($auditColumns[$column]),
            "Falta auditoria.{$column}."
        );
    }

    $auditIndexes = array_unique(array_column(
        $wpdb->get_results("SHOW INDEX FROM {$auditsTable}", ARRAY_A),
        'Key_name'
    ));
    assertConfirmationMigration(
        in_array(
            'payment_confirmation_audit_event_key_unique',
            $auditIndexes,
            true
        ),
        'Falta unicidad durable de eventos funcionales.'
    );

    $method = new ReflectionMethod(MigrationManager::class, 'migrations');
    $method->setAccessible(true);
    $registered = array_filter(
        $method->invoke(null),
        static fn (object $migration): bool =>
            $migration instanceof CreatePaymentConfirmationAuditsTable
    );
    assertConfirmationMigration(
        count($registered) === 1
        && version_compare(Config::SCHEMA_VERSION, '0.15.0', '>='),
        'La migracion no esta registrada correctamente.'
    );

    echo "PASS payment-confirmation-persistence-migration-test\n";
} finally {
    $wpdb->query("DROP TABLE IF EXISTS {$auditsTable}");
    $wpdb->query("DROP TABLE IF EXISTS {$sessionsTable}");
}
