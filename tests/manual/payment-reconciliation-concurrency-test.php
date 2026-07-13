<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Migrations\CreatePaymentReconciliationsTable;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\LeaseAcquireResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\StatusTransitionResult;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationClaimRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertReconciliationConcurrency(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function createConcurrencyFixture(): int
{
    global $wpdb;

    $nonce = bin2hex(random_bytes(12));
    $now = gmdate('Y-m-d H:i:s');
    $inserted = $wpdb->insert(
        $wpdb->prefix . Config::TABLE_PREFIX . 'payment_reconciliations',
        [
            'public_id' => 'pr_' . substr(hash('sha256', 'public-' . $nonce), 0, 40),
            'webpay_return_id' => random_int(1000000, 2000000000),
            'origin_context_id' => random_int(1000000, 2000000000),
            'provider' => 'webpay_plus',
            'fingerprint_version' => 1,
            'financial_fingerprint' => hash('sha256', 'financial-' . $nonce),
            'site_scope' => 'site-concurrency-test',
            'origin' => 'woocommerce',
            'origin_resource_id' => (string) random_int(1000000, 2000000000),
            'gateway_id' => 'veciahorra_webpay_plus',
            'payment_attempt_id' => 'attempt-' . $nonce,
            'origin_key' => hash('sha256', 'origin-' . $nonce),
            'reconciliation_status' => PaymentReconciliation::STATUS_PENDING,
            'attempt_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );

    if ($inserted !== 1) {
        throw new RuntimeException('No se creo fixture concurrente.');
    }

    return (int) $wpdb->insert_id;
}

/**
 * @param list<array{action:string,owner:string,version:int,expected:string,next:string}> $specs
 * @return list<array{action:string,status:string,worker_owner:string,next_status:string}>
 */
function runReconciliationRace(int $id, array $specs): array
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'va-reconciliation-' . bin2hex(random_bytes(8));
    $barrier = $directory . DIRECTORY_SEPARATOR . 'go';
    $worker = __DIR__ . '/payment-reconciliation-claim-worker.php';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $processes = [];

    assertReconciliationConcurrency(
        mkdir($directory, 0700),
        'No se creo directorio de barrera.'
    );

    try {
        foreach ($specs as $index => $spec) {
            $ready = $directory . DIRECTORY_SEPARATOR . "ready-{$index}";
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                $worker,
                $spec['action'],
                (string) $id,
                $spec['owner'],
                '10',
                (string) $spec['version'],
                $spec['expected'],
                $spec['next'],
                $barrier,
                $ready,
            ], $descriptors, $pipes);
            assertReconciliationConcurrency(
                is_resource($process),
                'No se inicio proceso de competencia.'
            );
            fclose($pipes[0]);
            $processes[] = [
                'process' => $process,
                'pipes' => $pipes,
                'ready' => $ready,
            ];
        }

        $deadline = microtime(true) + 10.0;

        do {
            $readyCount = count(array_filter(
                $processes,
                static fn (array $item): bool => is_file($item['ready'])
            ));

            if ($readyCount === count($processes)) {
                break;
            }

            usleep(10000);
            clearstatcache();
        } while (microtime(true) < $deadline);

        assertReconciliationConcurrency(
            $readyCount === count($processes),
            'Los workers no alcanzaron la barrera.'
        );
        touch($barrier);
        $results = [];

        foreach ($processes as &$item) {
            $stdout = trim((string) stream_get_contents($item['pipes'][1]));
            $stderr = trim((string) stream_get_contents($item['pipes'][2]));
            fclose($item['pipes'][1]);
            fclose($item['pipes'][2]);
            $exit = proc_close($item['process']);
            $item['process'] = null;
            assertReconciliationConcurrency(
                $exit === 0 && $stderr === '',
                'Worker fallo: ' . $stderr
            );
            $decoded = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
            assertReconciliationConcurrency(
                is_array($decoded),
                'Worker no devolvio resultado serializado.'
            );
            $results[] = $decoded;
        }
        unset($item);

        return $results;
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

            if (is_file($item['ready'])) {
                unlink($item['ready']);
            }
        }

        if (is_file($barrier)) {
            unlink($barrier);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

global $wpdb;

(new CreatePaymentReconciliationsTable())->up();
$repository = new PaymentReconciliationClaimRepository();
$table = $wpdb->prefix . Config::TABLE_PREFIX . 'payment_reconciliations';
$ids = [];

try {
    $acquireId = createConcurrencyFixture();
    $ids[] = $acquireId;
    $ownerA = PaymentReconciliationClaimRepository::ownerId();
    $ownerB = PaymentReconciliationClaimRepository::ownerId();
    $acquireResults = runReconciliationRace($acquireId, [
        [
            'action' => 'acquire',
            'owner' => $ownerA,
            'version' => 0,
            'expected' => '',
            'next' => '',
        ],
        [
            'action' => 'acquire',
            'owner' => $ownerB,
            'version' => 0,
            'expected' => '',
            'next' => '',
        ],
    ]);
    $acquireStatuses = array_column($acquireResults, 'status');
    sort($acquireStatuses);
    $winner = array_values(array_filter(
        $acquireResults,
        static fn (array $result): bool =>
            $result['status'] === LeaseAcquireResult::ACQUIRED
    ));
    $lease = $repository->findLease($acquireId);
    assertReconciliationConcurrency(
        $acquireStatuses === [LeaseAcquireResult::ACQUIRED, LeaseAcquireResult::BUSY]
        && count($winner) === 1
        && $lease?->owner() === $winner[0]['worker_owner']
        && $lease->attemptCount() === 1,
        'La adquisicion simultanea no produjo un ganador exclusivo.'
    );
    $acquireWinner = $winner[0]['worker_owner'];

    $casId = createConcurrencyFixture();
    $ids[] = $casId;
    $casOwner = PaymentReconciliationClaimRepository::ownerId();
    $casAcquire = $repository->acquireLease($casId, $casOwner, 10);
    assertReconciliationConcurrency(
        $casAcquire->acquired() && $casAcquire->lease() !== null,
        'No se preparo el lease para CAS concurrente.'
    );
    $casVersion = $casAcquire->lease()->version();
    $casResults = runReconciliationRace($casId, [
        [
            'action' => 'cas',
            'owner' => $casOwner,
            'version' => $casVersion,
            'expected' => PaymentReconciliation::STATUS_PROCESSING,
            'next' => PaymentReconciliation::STATUS_COMPLETED,
        ],
        [
            'action' => 'cas',
            'owner' => $casOwner,
            'version' => $casVersion,
            'expected' => PaymentReconciliation::STATUS_PROCESSING,
            'next' => PaymentReconciliation::STATUS_PERMANENT_FAILURE,
        ],
    ]);
    $applied = array_values(array_filter(
        $casResults,
        static fn (array $result): bool =>
            $result['status'] === StatusTransitionResult::APPLIED
    ));
    $rejected = array_values(array_filter(
        $casResults,
        static fn (array $result): bool =>
            $result['status'] === StatusTransitionResult::UNEXPECTED_STATE
    ));
    $final = $repository->findLease($casId);
    assertReconciliationConcurrency(
        count($applied) === 1
        && count($rejected) === 1
        && $final?->reconciliationStatus() === $applied[0]['next_status']
        && $final->owner() === null,
        'Dos CAS incompatibles aplicaron o se perdio la actualizacion.'
    );

    echo 'PASS payment-reconciliation-concurrency-test'
        . ' acquire_winner=' . $acquireWinner
        . ' cas_winner=' . $applied[0]['next_status'] . "\n";
} finally {
    foreach ($ids as $fixtureId) {
        $wpdb->delete($table, ['id' => $fixtureId], ['%d']);
    }
}
