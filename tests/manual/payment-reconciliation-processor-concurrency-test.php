<?php

declare(strict_types=1);

use VeciAhorra\Modules\Payments\Reconciliation\DTO\LeaseAcquireResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\PaymentReconciliationProcessingResult;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationClaimRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once __DIR__ . '/payment-reconciliation-processor-fixture.php';

function assertProcessorConcurrency(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

migrateProcessorFixtures();
$fixture = createProcessorFixture('multiprocess');
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'va-processor-' . bin2hex(random_bytes(8));
$barrier = $directory . DIRECTORY_SEPARATOR . 'go';
$entryFile = $directory . DIRECTORY_SEPARATOR . 'entries';
$continueFile = $directory . DIRECTORY_SEPARATOR . 'continue';
$worker = __DIR__ . '/payment-reconciliation-processor-worker.php';
$processes = [];
$owners = [
    PaymentReconciliationClaimRepository::ownerId(),
    PaymentReconciliationClaimRepository::ownerId(),
];
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

try {
    assertProcessorConcurrency(mkdir($directory, 0700), 'No se creo barrera.');

    foreach ($owners as $index => $owner) {
        $ready = $directory . DIRECTORY_SEPARATOR . 'ready-' . $index;
        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            $worker,
            (string) $fixture['id'],
            $owner,
            $barrier,
            $ready,
            $entryFile,
            $continueFile,
        ], $descriptors, $pipes);
        assertProcessorConcurrency(is_resource($process), 'No se inicio worker.');
        fclose($pipes[0]);
        $processes[] = [
            'process' => $process,
            'pipes' => $pipes,
            'ready' => $ready,
        ];
    }

    $deadline = microtime(true) + 10.0;

    do {
        clearstatcache();
        $ready = count(array_filter(
            $processes,
            static fn (array $item): bool => is_file($item['ready'])
        ));

        if ($ready === 2) {
            break;
        }

        usleep(10000);
    } while (microtime(true) < $deadline);

    assertProcessorConcurrency($ready === 2, 'Workers no alcanzaron barrera.');
    touch($barrier);
    $deadline = microtime(true) + 10.0;

    do {
        clearstatcache(true, $entryFile);
        $entries = is_file($entryFile)
            ? array_values(array_filter(file($entryFile, FILE_IGNORE_NEW_LINES)))
            : [];
        $finished = count(array_filter(
            $processes,
            static fn (array $item): bool => ! proc_get_status($item['process'])['running']
        ));

        if (count($entries) === 1 && $finished >= 1) {
            break;
        }

        usleep(10000);
    } while (microtime(true) < $deadline);

    assertProcessorConcurrency(
        count($entries) === 1 && $finished >= 1,
        'No se observo exclusion dentro del cuerpo tecnico.'
    );
    touch($continueFile);
    $results = [];

    foreach ($processes as &$item) {
        $stdout = trim((string) stream_get_contents($item['pipes'][1]));
        $stderr = trim((string) stream_get_contents($item['pipes'][2]));
        fclose($item['pipes'][1]);
        fclose($item['pipes'][2]);
        $exit = proc_close($item['process']);
        $item['process'] = null;
        assertProcessorConcurrency(
            $exit === 0 && $stderr === '',
            'Worker fallo: ' . $stderr
        );
        $results[] = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
    }
    unset($item);

    $acquireStatuses = array_column($results, 'acquire_status');
    sort($acquireStatuses);
    $processingStatuses = array_values(array_filter(
        array_column($results, 'processing_status'),
        static fn (mixed $status): bool => $status !== null
    ));
    $state = (new PaymentReconciliationClaimRepository())->findLease($fixture['id']);
    assertProcessorConcurrency(
        $acquireStatuses === [LeaseAcquireResult::ACQUIRED, LeaseAcquireResult::BUSY]
        && $processingStatuses === [PaymentReconciliationProcessingResult::PROCESSED]
        && count($entries) === 1
        && $entries[0] === array_values(array_filter(
            $results,
            static fn (array $result): bool =>
                $result['acquire_status'] === LeaseAcquireResult::ACQUIRED
        ))[0]['owner']
        && $state?->reconciliationStatus() === PaymentReconciliation::STATUS_COMPLETED
        && $state->attemptCount() === 1
        && $state->version() === 1,
        'Dos workers adquirieron o procesaron la conciliacion.'
    );

    echo 'PASS payment-reconciliation-processor-concurrency-test'
        . ' winner=' . $entries[0]
        . ' body_entries=1 loser=busy attempt_count=1' . PHP_EOL;
} finally {
    foreach ($processes as $item) {
        if (is_resource($item['process'])) {
            proc_terminate($item['process']);
            proc_close($item['process']);
        }

        foreach ($item['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    foreach ([$barrier, $entryFile, $continueFile] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    foreach ($processes as $item) {
        if (is_file($item['ready'])) {
            unlink($item['ready']);
        }
    }

    if (is_dir($directory)) {
        rmdir($directory);
    }

    deleteProcessorFixture($fixture);
}
