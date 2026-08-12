<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferResult;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryInitialTransferRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

global $wpdb;

$workerPosition = array_search('--worker', $argv, true);
if ($workerPosition !== false) {
    $subjectId = (int) ($argv[$workerPosition + 1] ?? 0);
    $scheduledFor = (string) ($argv[$workerPosition + 2] ?? '');
    $workerRequest = DurableRetryInitialTransferRequest::reconciliation(
        DurableRetryAuthorityIdentity::reconciliation($subjectId),
        $subjectId,
        new DateTimeImmutable($scheduledFor, new DateTimeZone('UTC'))
    );
    $workerQueries = [];
    $workerCapture = static function (string $sql) use (&$workerQueries): string {
        $workerQueries[] = strtok(ltrim($sql), " \r\n\t");

        return $sql;
    };
    add_filter('query', $workerCapture);
    try {
        $workerResult = (new DurableRetryInitialTransferRepository($wpdb))
            ->transferReconciliation($workerRequest);
    } finally {
        remove_filter('query', $workerCapture);
    }
    echo json_encode([
        'state' => $workerResult->state(),
        'queries' => $workerQueries,
        'database_error' => $wpdb->last_error !== '',
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$functionalTable = $prefix . 'payment_reconciliations';
$durableTable = $prefix . 'durable_retry_schedules';
foreach ([$functionalTable, $durableTable] as $table) {
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    $assert($found === $table, "Required table {$table} must exist.");
}
$assert(
    $durableTable === $wpdb->prefix . 'va_durable_retry_schedules',
    'Durable table must use the real WordPress and plugin prefixes.'
);
$assert(
    ! str_contains($durableTable, 'veciahorra_durable_retry_schedules'),
    'Forbidden durable table name must be absent.'
);

$suffix = bin2hex(random_bytes(10));
$now = gmdate('Y-m-d H:i:s');
$functionalId = 0;
$functionalIds = [];

try {
    $created = $wpdb->insert($functionalTable, [
        'public_id' => hash('sha256', "a4-public-{$suffix}"),
        'webpay_return_id' => random_int(8_500_000_000, 8_599_999_999),
        'origin_context_id' => random_int(8_600_000_000, 8_699_999_999),
        'provider' => 'webpay_plus',
        'fingerprint_version' => 1,
        'financial_fingerprint' => hash('sha256', "a4-financial-{$suffix}"),
        'site_scope' => 'a4_mysql',
        'origin' => 'checkout',
        'origin_resource_id' => hash('sha256', "a4-resource-{$suffix}"),
        'gateway_id' => 'webpay_plus',
        'payment_attempt_id' => hash('sha256', "a4-attempt-{$suffix}"),
        'origin_key' => hash('sha256', "a4-origin-{$suffix}"),
        'reconciliation_status' => 'pending',
        'business_result_code' => null,
        'attempt_count' => 0,
        'lease_owner' => null,
        'lease_acquired_at' => null,
        'lease_expires_at' => null,
        'lease_version' => 0,
        'last_error_code' => null,
        'last_error_at' => null,
        'created_at' => $now,
        'last_attempt_at' => null,
        'reconciled_at' => null,
        'updated_at' => $now,
    ]);
    $assert($created === 1, 'Functional fixture must be created.');
    $functionalId = (int) $wpdb->insert_id;
    $functionalIds[] = $functionalId;

    $request = DurableRetryInitialTransferRequest::reconciliation(
        DurableRetryAuthorityIdentity::reconciliation($functionalId),
        $functionalId,
        new DateTimeImmutable($now, new DateTimeZone('UTC'))
    );
    $repository = new DurableRetryInitialTransferRepository($wpdb);
    $independentMethod = new ReflectionMethod($repository, 'independentConnection');
    $externalDatabase = $independentMethod->invoke($repository);
    $assert($externalDatabase instanceof wpdb, 'Independent reconciliation connection must be creatable.');
    $assert($externalDatabase !== $wpdb, 'External evidence must use a distinct wpdb object.');
    $assert($externalDatabase->prefix === $wpdb->prefix, 'Independent connection preserves the real WordPress prefix.');
    $assert((int) $externalDatabase->get_var('SELECT 1') === 1, 'Independent connection is readable.');
    $externalDatabase->close();

    $captured = [];
    $capture = static function (string $sql) use (&$captured): string {
        $captured[] = $sql;

        return $sql;
    };
    add_filter('query', $capture);
    try {
        $first = $repository->transferReconciliation($request);
    } finally {
        remove_filter('query', $capture);
    }
    $assert($first->state() === DurableRetryInitialTransferResult::TRANSFERRED, 'First transfer must create durable authority.');
    $assert($captured[0] === 'START TRANSACTION', 'Transaction must start first.');
    $functionalLockIndex = null;
    $durableLockIndex = null;
    foreach ($captured as $index => $sql) {
        if ($functionalLockIndex === null
            && str_contains($sql, $functionalTable)
            && str_contains($sql, 'FOR UPDATE')
        ) {
            $functionalLockIndex = $index;
        }
        if ($durableLockIndex === null
            && str_contains($sql, $durableTable)
            && str_contains($sql, 'FOR UPDATE')
        ) {
            $durableLockIndex = $index;
        }
    }
    $assert(
        is_int($functionalLockIndex)
            && is_int($durableLockIndex)
            && $functionalLockIndex < $durableLockIndex,
        'Functional lock must precede durable lock.'
    );
    $assert(
        count(array_filter(
            $captured,
            static fn (string $sql): bool => preg_match('/^\s*INSERT\b/i', $sql) === 1
        )) === 1,
        'Created transfer must attempt exactly one insert.'
    );
    $assert(end($captured) === 'COMMIT', 'Created transfer must commit.');
    $phaseProperty = new ReflectionProperty($repository, 'phaseHistory');
    $assert($phaseProperty->getValue($repository) === [
        'PRE_TRANSACTION',
        'TRANSACTION_STARTED',
        'READS_AND_LOCKS',
        'PRE_WRITE',
        'WRITE_ATTEMPTED',
        'COMMIT_ATTEMPTED',
        'CLOSE_CONFIRMED',
    ], 'MySQL creation follows the exact normative phase sequence.');

    $rows = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . $durableTable
            . ' WHERE stage = %s AND subject_id = %d AND generation = 1',
        'reconciliation',
        $functionalId
    ));
    $assert($rows === 1, 'Exactly one generation 1 must exist.');

    $second = $repository->transferReconciliation($request);
    $assert($second->state() === DurableRetryInitialTransferResult::ALREADY_TRANSFERRED, 'Repeated transfer must converge.');
    $rowsAfterRepeat = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . $durableTable
            . ' WHERE stage = %s AND subject_id = %d',
        'reconciliation',
        $functionalId
    ));
    $assert($rowsAfterRepeat === 1, 'Repeated transfer must not duplicate durable rows.');

    $wpdb->update(
        $functionalTable,
        [
            'reconciliation_status' => 'processing',
            'attempt_count' => 1,
            'lease_owner' => 'worker_' . str_repeat('b', 32),
            'lease_acquired_at' => $now,
            'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 600),
            'lease_version' => 1,
        ],
        ['id' => $functionalId]
    );
    $busy = $repository->transferReconciliation($request);
    $assert($busy->state() === DurableRetryInitialTransferResult::LEGACY_IN_FLIGHT, 'Active legacy lease must win serialization.');
    $assert(
        (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $durableTable
                . ' WHERE stage = %s AND subject_id = %d',
            'reconciliation',
            $functionalId
        )) === 1,
        'Legacy claim branch must not mutate durable authority.'
    );

    $indexes = $wpdb->get_results('SHOW INDEX FROM ' . $durableTable, ARRAY_A);
    $identityIndex = array_filter(
        $indexes,
        static fn (array $row): bool =>
            ($row['Key_name'] ?? '') === 'durable_retry_identity_unique'
            && (int) ($row['Non_unique'] ?? 1) === 0
    );
    $assert(count($identityIndex) === 3, 'Identity unique key must cover three columns.');

    $raceSuffix = bin2hex(random_bytes(10));
    $raceCreated = $wpdb->insert($functionalTable, [
        'public_id' => hash('sha256', "a4-race-public-{$raceSuffix}"),
        'webpay_return_id' => random_int(8_700_000_000, 8_799_999_999),
        'origin_context_id' => random_int(8_800_000_000, 8_899_999_999),
        'provider' => 'webpay_plus',
        'fingerprint_version' => 1,
        'financial_fingerprint' => hash('sha256', "a4-race-financial-{$raceSuffix}"),
        'site_scope' => 'a4_mysql_race',
        'origin' => 'checkout',
        'origin_resource_id' => hash('sha256', "a4-race-resource-{$raceSuffix}"),
        'gateway_id' => 'webpay_plus',
        'payment_attempt_id' => hash('sha256', "a4-race-attempt-{$raceSuffix}"),
        'origin_key' => hash('sha256', "a4-race-origin-{$raceSuffix}"),
        'reconciliation_status' => 'pending',
        'business_result_code' => null,
        'attempt_count' => 0,
        'lease_owner' => null,
        'lease_acquired_at' => null,
        'lease_expires_at' => null,
        'lease_version' => 0,
        'last_error_code' => null,
        'last_error_at' => null,
        'created_at' => $now,
        'last_attempt_at' => null,
        'reconciled_at' => null,
        'updated_at' => $now,
    ]);
    $assert($raceCreated === 1, 'Concurrent functional fixture must be created.');
    $raceId = (int) $wpdb->insert_id;
    $functionalIds[] = $raceId;
    $assert($wpdb->query('COMMIT') !== false, 'Concurrent fixture must be visible to independent connections.');
    $command = escapeshellarg(PHP_BINARY)
        . ' -d error_reporting=-1 -d display_errors=1 '
        . escapeshellarg(__FILE__)
        . ' --worker ' . $raceId . ' ' . escapeshellarg($now);
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $firstProcess = proc_open($command, $descriptor, $firstPipes);
    $secondProcess = proc_open($command, $descriptor, $secondPipes);
    $assert(is_resource($firstProcess) && is_resource($secondProcess), 'Two independent workers must start.');
    fclose($firstPipes[0]);
    fclose($secondPipes[0]);
    $firstOutput = trim(stream_get_contents($firstPipes[1]));
    $secondOutput = trim(stream_get_contents($secondPipes[1]));
    $firstError = trim(stream_get_contents($firstPipes[2]));
    $secondError = trim(stream_get_contents($secondPipes[2]));
    fclose($firstPipes[1]);
    fclose($firstPipes[2]);
    fclose($secondPipes[1]);
    fclose($secondPipes[2]);
    $firstExit = proc_close($firstProcess);
    $secondExit = proc_close($secondProcess);
    $assert($firstExit === 0 && $secondExit === 0, 'Concurrent workers must exit cleanly.');
    $assert($firstError === '' && $secondError === '', 'Concurrent workers must emit no diagnostics.');
    $firstWorker = json_decode($firstOutput, true, flags: JSON_THROW_ON_ERROR);
    $secondWorker = json_decode($secondOutput, true, flags: JSON_THROW_ON_ERROR);
    $raceStates = [$firstWorker['state'], $secondWorker['state']];
    sort($raceStates);
    $expectedRaceStates = [
        DurableRetryInitialTransferResult::ALREADY_TRANSFERRED,
        DurableRetryInitialTransferResult::TRANSFERRED,
    ];
    sort($expectedRaceStates);
    $assert(
        $raceStates === $expectedRaceStates,
        'Concurrent transfers must create once and converge once: '
            . json_encode([$firstWorker, $secondWorker], JSON_THROW_ON_ERROR)
    );
    $firstInserts = count(array_filter(
        $firstWorker['queries'],
        static fn (string $operation): bool => strtoupper($operation) === 'INSERT'
    ));
    $secondInserts = count(array_filter(
        $secondWorker['queries'],
        static fn (string $operation): bool => strtoupper($operation) === 'INSERT'
    ));
    $assert(
        $firstInserts + $secondInserts === 1
            && min($firstInserts, $secondInserts) === 0,
        'The functional lock makes a real duplicate-key race unreachable: '
            . 'the winner inserts once and the serialized loser performs no write.'
    );
    $assert(
        (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $durableTable
                . ' WHERE stage = %s AND subject_id = %d AND generation = 1',
            'reconciliation',
            $raceId
        )) === 1,
        'Concurrent transfer must leave exactly one generation 1.'
    );
    $assert($wpdb->last_error === '', 'MySQL harness must finish without database error.');
} finally {
    foreach ($functionalIds as $fixtureId) {
        $wpdb->delete(
            $durableTable,
            ['stage' => 'reconciliation', 'subject_id' => $fixtureId]
        );
        $wpdb->delete($functionalTable, ['id' => $fixtureId]);
    }
}

echo "OK durable retry initial transfer authority mysql ({$assertions} assertions)\n";
