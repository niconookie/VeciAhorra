<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\MigrationManager;
use VeciAhorra\Database\Migrations\CreatePaymentOriginContextsTable;
use VeciAhorra\Database\Migrations\CreatePaymentReconciliationsTable;
use VeciAhorra\Database\Migrations\CreateWebpayReturnsTable;

require_once dirname(__DIR__, 5) . '/wp-load.php';

global $wpdb;

$suffix = uniqid('', true);
$originTable = $wpdb->prefix . 'va_test_origin_' . str_replace('.', '', $suffix);
$returnTable = $wpdb->prefix . 'va_test_return_' . str_replace('.', '', $suffix);
$reconciliationTable = $wpdb->prefix . 'va_test_recon_' . str_replace('.', '', $suffix);

try {
    $wpdb->query(sprintf(
        'CREATE TABLE %s ('
        . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . 'token_hash VARCHAR(64) NOT NULL,'
        . 'payment_session_id BIGINT UNSIGNED NULL,'
        . 'flow VARCHAR(20) NOT NULL,'
        . 'processing_status VARCHAR(20) NOT NULL,'
        . 'result_status VARCHAR(30) NULL,'
        . 'result_json TEXT NULL,'
        . 'created_at DATETIME NOT NULL,'
        . 'updated_at DATETIME NOT NULL,'
        . 'PRIMARY KEY (id),'
        . 'UNIQUE KEY webpay_returns_token_hash_unique (token_hash),'
        . 'KEY webpay_returns_session_index (payment_session_id)'
        . ') ENGINE=InnoDB %s',
        $returnTable,
        $wpdb->get_charset_collate()
    ));
    $wpdb->insert($returnTable, [
        'token_hash' => str_repeat('a', 64),
        'payment_session_id' => null,
        'flow' => 'commit',
        'processing_status' => 'completed',
        'result_status' => 'approved',
        'result_json' => '{"status":"approved"}',
        'created_at' => '2026-07-01 12:00:00',
        'updated_at' => '2026-07-01 12:00:00',
    ]);

    foreach ([
        new CreatePaymentOriginContextsTable($originTable),
        new CreateWebpayReturnsTable($returnTable),
        new CreatePaymentReconciliationsTable($reconciliationTable),
    ] as $migration) {
        $migration->up();
        $migration->up();
    }

    $historical = $wpdb->get_row(
        "SELECT * FROM {$returnTable} WHERE token_hash = '"
        . str_repeat('a', 64) . "' LIMIT 1",
        ARRAY_A
    );
    $newFinancialColumns = [
        'public_result_id', 'provider', 'environment',
        'merchant_identity_hash', 'financial_status', 'financial_operation',
        'financial_fingerprint', 'fingerprint_version', 'provider_status',
        'response_code', 'amount_clp', 'currency', 'buy_order',
        'financial_session_id', 'authorization_code_hash',
        'payment_type_code', 'installments_number', 'accounting_date',
        'transaction_date', 'safe_financial_reference', 'payload_version',
        'normalized_payload_json', 'financial_obtained_at',
        'financial_validated_at',
    ];

    foreach ($newFinancialColumns as $column) {
        if (
            ! is_array($historical)
            || ! array_key_exists($column, $historical)
            || $historical[$column] !== null
        ) {
            throw new RuntimeException(
                "La migracion relleno evidencia historica en {$column}."
            );
        }
    }

    $required = [
        $originTable => [
            'public_id', 'site_scope', 'origin', 'origin_resource_id',
            'gateway_id', 'payment_attempt_id', 'origin_key', 'amount_clp',
            'currency', 'environment', 'merchant_identity_hash', 'buy_order',
            'financial_session_id', 'token_hash', 'context_version', 'expires_at',
        ],
        $returnTable => [
            'financial_status', 'financial_operation', 'financial_fingerprint',
            'provider_status', 'response_code', 'amount_clp', 'currency',
            'normalized_payload_json', 'financial_validated_at',
        ],
        $reconciliationTable => [
            'webpay_return_id', 'origin_context_id', 'financial_fingerprint',
            'origin_key', 'reconciliation_status', 'attempt_count',
            'last_error_code', 'reconciled_at',
        ],
    ];

    foreach ($required as $table => $columns) {
        $stored = array_column(
            $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A),
            null,
            'Field'
        );

        foreach ($columns as $column) {
            if (! isset($stored[$column])) {
                throw new RuntimeException("Falta {$column} en {$table}.");
            }
        }
    }

    $expectedUnique = [
        $originTable => [
            'payment_origin_contexts_attempt_unique',
            'payment_origin_contexts_token_unique',
            'payment_origin_contexts_origin_key_unique',
        ],
        $returnTable => ['webpay_returns_fingerprint_unique'],
        $reconciliationTable => [
            'payment_reconciliations_fingerprint_unique',
            'payment_reconciliations_origin_key_unique',
            'payment_reconciliations_return_unique',
        ],
    ];

    foreach ($expectedUnique as $table => $names) {
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);

        foreach ($names as $name) {
            $found = array_filter(
                $indexes,
                static fn (array $index): bool =>
                    $index['Key_name'] === $name
                    && (string) $index['Non_unique'] === '0'
            );

            if ($found === []) {
                throw new RuntimeException("Falta indice unico {$name}.");
            }
        }
    }

    $method = new ReflectionMethod(MigrationManager::class, 'migrations');
    $method->setAccessible(true);
    $registered = $method->invoke(null);

    if (
        count(array_filter($registered, fn (object $item): bool =>
            $item instanceof CreatePaymentOriginContextsTable)) !== 1
        || count(array_filter($registered, fn (object $item): bool =>
            $item instanceof CreatePaymentReconciliationsTable)) !== 1
        || version_compare(Config::SCHEMA_VERSION, '0.17.0', '<')
    ) {
        throw new RuntimeException('Las migraciones no estan registradas.');
    }

    echo "PASS payment-reconciliation-migration-test\n";
} finally {
    foreach ([$reconciliationTable, $returnTable, $originTable] as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }
}
