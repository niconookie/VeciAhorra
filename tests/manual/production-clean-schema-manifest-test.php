<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Core\Config;

function cleanSchemaAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

$auditPath = $argv[1] ?? dirname(__DIR__, 2) . '/artifacts/production-clean-deployment-package/rehearsal-database-audit.json';
cleanSchemaAssert(is_file($auditPath), 'Falta la evidencia JSON de la instalacion limpia.');
$audit = json_decode((string) file_get_contents($auditPath), true, 512, JSON_THROW_ON_ERROR);

$suffixes = [
    'business_completions', 'business_completion_orders', 'cart_items', 'checkouts', 'checkout_orders',
    'couriers', 'deliveries', 'delivery_completions', 'delivery_tracking', 'durable_retry_schedules',
    'fulfillment_completions', 'inventory', 'orders', 'order_items', 'payments',
    'payment_confirmation_audits', 'payment_orders', 'payment_origin_contexts', 'payment_reconciliations',
    'payment_sessions', 'products', 'reservations', 'service_providers', 'service_zones', 'stores',
    'store_decision_history', 'store_onboarding_activation_sessions', 'store_onboarding_applications',
    'store_onboarding_email_verifications', 'store_onboarding_rate_limit_buckets', 'store_service_zones',
    'webpay_returns', 'zonal_admin_service_zones',
];
$expected = array_map(static fn (string $name): string => 'wp_' . Config::TABLE_PREFIX . $name, $suffixes);
$actual = array_values(array_map('strval', $audit['veciahorra_tables'] ?? []));
sort($expected, SORT_STRING);
sort($actual, SORT_STRING);

cleanSchemaAssert(Config::SCHEMA_VERSION === '0.32.0', 'La autoridad de schema no es 0.32.0.');
cleanSchemaAssert(($audit['schema_version'] ?? null) === Config::SCHEMA_VERSION, 'La instalacion limpia no reporta schema 0.32.0.');
cleanSchemaAssert(($audit['veciahorra_table_count'] ?? null) === 33, 'La instalacion limpia no reporta count exacto 33.');
cleanSchemaAssert(count($actual) === 33 && count(array_unique($actual)) === 33, 'La evidencia contiene duplicados o cardinalidad incorrecta.');
cleanSchemaAssert($actual === $expected, 'Falta una tabla autorizada o existe una tabla VeciAhorra inesperada.');

$migration = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Database/Migrations/CreateZonalAdminFoundationTables.php');
$manager = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Database/MigrationManager.php');
cleanSchemaAssert(str_contains($migration, 'ZonalAdminServiceZonesTable'), 'Falta la autoridad de la tabla numero 33.');
cleanSchemaAssert(str_contains($manager, 'new CreateZonalAdminFoundationTables()'), 'La migracion zonal no esta registrada.');

echo "PRODUCTION_CLEAN_SCHEMA=PASS version=0.32.0 exact_tables=33 missing_historical=zonal_admin_service_zones unexpected=0\n";
