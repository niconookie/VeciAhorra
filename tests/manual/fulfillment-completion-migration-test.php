<?php

declare(strict_types=1);

use VeciAhorra\Database\MigrationManager;
use VeciAhorra\Database\Migrations\CreateFulfillmentCompletionsTable;
use VeciAhorra\Database\Migrations\EnsureUniqueFulfillmentCompletion;
use VeciAhorra\Exceptions\PersistenceException;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function fcmAssert(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

function fcmExactUniqueIndex(string $table, string $column): ?string
{
    global $wpdb;
    $groups = [];
    foreach ($wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A) as $index) {
        if ((int) $index['Non_unique'] !== 0) { continue; }
        $groups[(string) $index['Key_name']][(int) $index['Seq_in_index']] = (string) $index['Column_name'];
    }
    foreach ($groups as $name => $columns) {
        ksort($columns);
        if (array_values($columns) === [$column]) { return $name; }
    }
    return null;
}

function fcmInsert(string $table, int $businessId, string $key): void
{
    global $wpdb;
    $now = current_time('mysql', true);
    fcmAssert($wpdb->insert($table, [
        'business_completion_id' => $businessId,
        'idempotency_key' => $key,
        'completion_status' => 'pending',
        'created_at' => $now,
        'updated_at' => $now,
    ]) === 1, 'No se pudo crear fixture historico.');
}

global $wpdb;
$table = $wpdb->prefix . 'veciahorra_fc_migration_' . bin2hex(random_bytes(4));

try {
    (new CreateFulfillmentCompletionsTable($table))->up();
    (new EnsureUniqueFulfillmentCompletion($table))->up();
    fcmAssert(fcmExactUniqueIndex($table, 'business_completion_id') !== null, 'Instalacion nueva sin unique business_completion_id.');
    fcmAssert(fcmExactUniqueIndex($table, 'idempotency_key') !== null, 'Instalacion nueva sin unique idempotency_key.');

    (new EnsureUniqueFulfillmentCompletion($table))->up();
    fcmAssert(fcmExactUniqueIndex($table, 'business_completion_id') !== null
        && fcmExactUniqueIndex($table, 'idempotency_key') !== null,
        'La repeticion no fue idempotente.');

    $missingIndex = fcmExactUniqueIndex($table, 'idempotency_key');
    fcmAssert($missingIndex !== null, 'No existe indice para simular ausencia.');
    $wpdb->query("ALTER TABLE `{$table}` DROP INDEX `{$missingIndex}`");
    fcmAssert(fcmExactUniqueIndex($table, 'idempotency_key') === null, 'No se elimino el indice fixture.');
    (new EnsureUniqueFulfillmentCompletion($table))->up();
    fcmAssert(fcmExactUniqueIndex($table, 'idempotency_key') !== null, 'No se restauro el indice ausente.');

    $missingBusinessIndex = fcmExactUniqueIndex($table, 'business_completion_id');
    fcmAssert($missingBusinessIndex !== null, 'No existe indice business para simular ausencia.');
    $wpdb->query("ALTER TABLE `{$table}` DROP INDEX `{$missingBusinessIndex}`");
    fcmAssert(fcmExactUniqueIndex($table, 'business_completion_id') === null, 'No se elimino el indice business fixture.');
    (new EnsureUniqueFulfillmentCompletion($table))->up();
    fcmAssert(fcmExactUniqueIndex($table, 'business_completion_id') !== null, 'No se restauro el indice business ausente.');

    $businessIndex = fcmExactUniqueIndex($table, 'business_completion_id');
    fcmAssert($businessIndex !== null, 'No existe unique business para fixture.');
    $wpdb->query("ALTER TABLE `{$table}` DROP INDEX `{$businessIndex}`");
    fcmInsert($table, 700001, hash('sha256', 'business-duplicate-a'));
    fcmInsert($table, 700001, hash('sha256', 'business-duplicate-b'));
    try {
        (new EnsureUniqueFulfillmentCompletion($table))->up();
        fcmAssert(false, 'La migracion acepto business_completion_id duplicado.');
    } catch (PersistenceException) {
        fcmAssert((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`") === 2, 'La migracion modifico duplicados business historicos.');
        fcmAssert(fcmExactUniqueIndex($table, 'business_completion_id') === null, 'La migracion instalo parcialmente el indice incompatible.');
    }

    $wpdb->query("TRUNCATE TABLE `{$table}`");
    (new EnsureUniqueFulfillmentCompletion($table))->up();
    $keyIndex = fcmExactUniqueIndex($table, 'idempotency_key');
    fcmAssert($keyIndex !== null, 'No existe unique key para fixture.');
    $wpdb->query("ALTER TABLE `{$table}` DROP INDEX `{$keyIndex}`");
    $duplicateKey = hash('sha256', 'idempotency-duplicate');
    fcmInsert($table, 700002, $duplicateKey);
    fcmInsert($table, 700003, $duplicateKey);
    try {
        (new EnsureUniqueFulfillmentCompletion($table))->up();
        fcmAssert(false, 'La migracion acepto idempotency_key duplicada.');
    } catch (PersistenceException) {
        fcmAssert((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`") === 2, 'La migracion modifico duplicados key historicos.');
        fcmAssert(fcmExactUniqueIndex($table, 'idempotency_key') === null, 'La migracion instalo parcialmente el indice key incompatible.');
    }

    $method = new ReflectionMethod(MigrationManager::class, 'migrations');
    $method->setAccessible(true);
    $migrations = $method->invoke(null);
    $classes = array_map('get_class', $migrations);
    $createPosition = array_search(CreateFulfillmentCompletionsTable::class, $classes, true);
    $ensurePosition = array_search(EnsureUniqueFulfillmentCompletion::class, $classes, true);
    fcmAssert(is_int($createPosition) && is_int($ensurePosition) && $ensurePosition === $createPosition + 1,
        'La verificacion durable no esta registrada tras la creacion.');

    echo "PASS fulfillment-completion-migration-test\n";
} finally {
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}
