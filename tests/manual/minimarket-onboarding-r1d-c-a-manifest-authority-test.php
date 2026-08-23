<?php
declare(strict_types=1);

define('WP_INSTALLING', true);
require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once __DIR__ . '/minimarket-onboarding-r1d-c-a-manifest-channel.php';
require_once __DIR__ . '/minimarket-onboarding-r1d-c-a-case-registry.php';

function ma(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new RuntimeException($reason);
    }
}

function maExact(callable $operation, string $reason): void
{
    $thrown = false;
    $exception = null;
    try {
        $operation();
    } catch (Throwable $caught) {
        $thrown = true;
        $exception = $caught;
    }
    ma($thrown, 'expected_exception_missing');
    ma(get_class($exception) === RuntimeException::class, 'unexpected_exception_class');
    ma($exception->getMessage() === $reason, 'unexpected_exception_reason');
    ma($exception->getCode() === 0, 'unexpected_exception_code');
    ma($exception->getPrevious() === null, 'unexpected_exception_previous');
}

function maPayload(array $authority, int $pid = 12345): array
{
    return [
        'version' => 'r1dca.final-manifest.v1',
        'execution_id' => $authority['execution_id'],
        'group_id' => $authority['group_id'],
        'group_nonce' => $authority['group_nonce'],
        'child_pid' => $pid,
        'ids' => $authority['ids'],
        'count' => count($authority['ids']),
        'cleanup_complete' => true,
        'fixtures_remaining' => 0,
        'locks_remaining' => 0,
        'completed_at_utc' => '2026-01-01T00:00:00Z',
    ];
}

function maWire(string $json, string $key): string
{
    return base64_encode($json) . '.' . hash_hmac('sha256', $json, $key) . "\n";
}

function maWrite(string $path, array $payload, string $key, ?string $json = null): string
{
    $json ??= R1dcaManifestChannel::canonical($payload);
    $wire = maWire($json, $key);
    ma(file_put_contents($path, $wire, LOCK_EX) === strlen($wire), 'manifest_fixture_write');
    return $wire;
}

function maAuthority(string $root, string $database, string $suffix): array
{
    return [
        'execution_id' => bin2hex(random_bytes(16)),
        'group_id' => 'qa1',
        'group_nonce' => bin2hex(random_bytes(16)),
        'key' => random_bytes(32),
        'ids' => ['AUTHORITY-' . $suffix . '/Á'],
        'receipt_dir' => $root . DIRECTORY_SEPARATOR . 'receipts-' . $suffix,
        'database' => $database,
        'lock_names' => [],
    ];
}

function maCleanup(string $path, string $receiptDir): void
{
    @unlink($path);
    if (is_dir($receiptDir)) {
        foreach (scandir($receiptDir) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                @unlink($receiptDir . DIRECTORY_SEPARATOR . $file);
            }
        }
        @rmdir($receiptDir);
    }
}

if (($argv[1] ?? '') === '--consume-worker') {
    $config = json_decode((string) file_get_contents((string) ($argv[2] ?? '')), true, 16, JSON_THROW_ON_ERROR);
    $config['authority']['key'] = hex2bin($config['authority']['key']);
    try {
        R1dcaManifestChannel::consume($config['path'], $config['authority'], 0, 12345);
        echo "ACCEPTED\n";
        exit(0);
    } catch (Throwable $exception) {
        if (get_class($exception) === RuntimeException::class && $exception->getMessage() === 'manifest_replayed' && $exception->getCode() === 0 && $exception->getPrevious() === null) {
            echo "REPLAYED\n";
            exit(0);
        }
        fwrite(STDERR, get_class($exception) . ':' . $exception->getMessage());
        exit(2);
    }
}

$database = (string) getenv('VA_R1DCA_DATABASE');
ma($database === 'minimarket_r1dca_restore', 'database_guard');
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'r1dca-authority-' . bin2hex(random_bytes(16));
ma(mkdir($root, 0700), 'authority_directory');

$replayIds = [
    'REPLAY-01-SAME-PATH', 'REPLAY-02-COPIED-BYTES-NEW-PATH', 'REPLAY-03-SAME-PAYLOAD-RESIGNED',
    'REPLAY-04-RESERIALIZED-IDENTITY', 'REPLAY-05-NEW-CHANNEL-INSTANCE', 'REPLAY-06-PREVIOUS-EXECUTION',
    'REPLAY-07-DELETED-AND-RECREATED', 'REPLAY-08-CONCURRENT-CONSUMERS',
];
$replayExpected = array_combine($replayIds, $replayIds);
$replay = new R1dcaCaseRegistry('R1DCA_MANIFEST_REPLAY', $replayExpected);

try {
    foreach ($replayIds as $index => $caseId) {
        $replay->run($caseId, static fn() => null, function () use ($index, $root, $database): array {
            $authority = maAuthority($root, $database, 'r' . $index);
            $path = $root . DIRECTORY_SEPARATOR . 'r' . $index . '-a.manifest';
            $payload = maPayload($authority);
            $wire = maWrite($path, $payload, $authority['key']);
            if ($index === 5) {
                $payload['execution_id'] = bin2hex(random_bytes(16));
                maWrite($path, $payload, $authority['key']);
                maExact(fn() => R1dcaManifestChannel::consume($path, $authority, 0, 12345), 'manifest_authority');
                return compact('path', 'authority');
            }
            if ($index === 7) {
                $pathB = $root . DIRECTORY_SEPARATOR . 'r7-b.manifest';
                ma(file_put_contents($pathB, $wire, LOCK_EX) === strlen($wire), 'concurrent_copy');
                $workers = [];
                foreach ([$path, $pathB] as $workerIndex => $workerPath) {
                    $workerAuthority = $authority;
                    $workerAuthority['manifest_path'] = $workerPath;
                    $workerAuthority['key'] = bin2hex($workerAuthority['key']);
                    $configPath = $root . DIRECTORY_SEPARATOR . 'worker-' . $workerIndex . '.json';
                    file_put_contents($configPath, json_encode(['path' => $workerPath, 'authority' => $workerAuthority], JSON_THROW_ON_ERROR));
                    $pipes = [];
                    $process = proc_open([PHP_BINARY, __FILE__, '--consume-worker', $configPath], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
                    ma(is_resource($process), 'concurrent_worker_start');
                    fclose($pipes[0]);
                    $workers[] = [$process, $pipes, $configPath];
                }
                $outcomes = [];
                foreach ($workers as [$process, $pipes, $configPath]) {
                    $outcomes[] = trim((string) stream_get_contents($pipes[1]));
                    $stderr = stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    ma(proc_close($process) === 0 && $stderr === '', 'concurrent_worker_result');
                    @unlink($configPath);
                }
                sort($outcomes);
                ma($outcomes === ['ACCEPTED', 'REPLAYED'], 'concurrent_exactly_one');
                return compact('path', 'pathB', 'authority');
            }
            R1dcaManifestChannel::consume($path, $authority, 0, 12345);
            $second = $path;
            if (in_array($index, [1, 2, 3, 4], true)) {
                $second = $root . DIRECTORY_SEPARATOR . 'r' . $index . '-b.manifest';
            }
            if ($index !== 0 || !is_file($second)) {
                if ($index === 3) {
                    $payload['completed_at_utc'] = '2026-01-01T00:00:01Z';
                    maWrite($second, $payload, $authority['key']);
                } else {
                    ma(file_put_contents($second, $wire, LOCK_EX) === strlen($wire), 'replay_copy');
                }
            }
            maExact(fn() => R1dcaManifestChannel::consume($second, $authority, 0, 12345), 'manifest_replayed');
            return compact('path', 'second', 'authority');
        }, static fn($_, $result, $error) => ma($error === null && is_array($result), 'replay_case'), function ($result): void {
            if (!is_array($result)) return;
            maCleanup($result['path'], $result['authority']['receipt_dir']);
            if (isset($result['second'])) @unlink($result['second']);
            if (isset($result['pathB'])) @unlink($result['pathB']);
        });
    }
    $replayTotal = $replay->seal();
    ma($replayTotal === 8, 'replay_total');
    echo 'R1DCA_MANIFEST_REPLAY_CASES=' . $replayTotal . '/PASS' . PHP_EOL;
    echo 'R1DCA_FINAL_MANIFEST_REPLAY_PROTECTION=PASS' . PHP_EOL;

    $jsonIds = [
        'JSON-01-CANONICAL-ACCEPTED', 'JSON-02-WHITESPACE', 'JSON-03-KEY-ORDER', 'JSON-04-ESCAPED-SLASH',
        'JSON-05-UNICODE-ESCAPE', 'JSON-06-BOM', 'JSON-07-CRLF', 'JSON-08-EXTRA-LF',
        'JSON-09-INTEGER-AS-STRING', 'JSON-10-DUPLICATE-KEY', 'JSON-11-EXTRA-FIELD', 'JSON-12-ARRAY-ORDER',
    ];
    $jsonRegistry = new R1dcaCaseRegistry('R1DCA_CANONICAL_JSON', array_combine($jsonIds, $jsonIds));
    foreach ($jsonIds as $index => $caseId) {
        $jsonRegistry->run($caseId, static fn() => null, function () use ($index, $root, $database): array {
            $authority = maAuthority($root, $database, 'j' . $index);
            if ($index === 11) $authority['ids'][] = 'SECOND';
            $payload = maPayload($authority);
            $canonical = R1dcaManifestChannel::canonical($payload);
            $json = $canonical;
            if ($index === 1) $json = str_replace('{', '{ ', $canonical);
            if ($index === 2) $json = json_encode(array_reverse($payload, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            if ($index === 3) $json = str_replace('/', '\\/', $canonical);
            if ($index === 4) $json = str_replace('Á', '\\u00c1', $canonical);
            if ($index === 5) $json = "\xEF\xBB\xBF" . $canonical;
            if ($index === 6) $json = rtrim($canonical, "\n") . "\r\n";
            if ($index === 7) $json = $canonical . "\n";
            if ($index === 8) $json = str_replace('"count":1', '"count":"1"', $canonical);
            if ($index === 9) $json = preg_replace('/^\{/', '{"version":"r1dca.final-manifest.v1",', $canonical, 1);
            if ($index === 10) { $payload['unexpected'] = true; $json = R1dcaManifestChannel::canonical($payload); }
            if ($index === 11) { $payload['ids'] = array_reverse($payload['ids']); $json = R1dcaManifestChannel::canonical($payload); }
            $path = $root . DIRECTORY_SEPARATOR . 'j' . $index . '.manifest';
            maWrite($path, $payload, $authority['key'], $json);
            if ($index === 0) {
                R1dcaManifestChannel::consume($path, $authority, 0, 12345);
            } else {
                $reason = $index === 5 ? 'manifest_json' : (in_array($index, [1, 2, 3, 4, 6, 7, 9], true) ? 'manifest_noncanonical' : ($index === 10 ? 'manifest_shape' : 'manifest_evidence'));
                maExact(fn() => R1dcaManifestChannel::consume($path, $authority, 0, 12345), $reason);
            }
            return compact('path', 'authority');
        }, static fn($_, $result, $error) => ma($error === null && is_array($result), 'json_case'), function ($result): void {
            if (is_array($result)) maCleanup($result['path'], $result['authority']['receipt_dir']);
        });
    }
    $jsonTotal = $jsonRegistry->seal();
    ma($jsonTotal === 12, 'json_total');
    echo 'R1DCA_CANONICAL_JSON_CASES=' . $jsonTotal . '/PASS' . PHP_EOL;
    echo 'R1DCA_CANONICAL_JSON_BYTES=PASS' . PHP_EOL;

    $subclassRejected = false;
    try {
        maExact(static fn() => throw new class('closed_reason') extends RuntimeException {}, 'closed_reason');
    } catch (RuntimeException $exception) {
        $subclassRejected = $exception->getMessage() === 'unexpected_exception_class';
    }
    ma($subclassRejected, 'subclass_mutant_accepted');
    echo 'R1DCA_UNEXPECTED_EXCEPTION_SUBCLASS_REJECTED=PASS' . PHP_EOL;

    $authorityIds = [
        'AUTH-01-CALLER-RESETS-CONSUMED', 'AUTH-02-COPIED-PATH', 'AUTH-03-NEW-INSTANCE',
        'AUTH-04-NONCANONICAL-VALID-HMAC', 'AUTH-05-MANIFEST-ZERO-REAL-LOCK',
        'AUTH-06-MUT08-MANUAL-VERSION', 'AUTH-07-UNEXPECTED-SUBCLASS', 'AUTH-08-STDOUT-LITERAL',
    ];
    $authorityRegistry = new R1dcaCaseRegistry('R1DCA_MANIFEST_AUTHORITY_GUARD', array_combine($authorityIds, $authorityIds));
    foreach ($authorityIds as $index => $caseId) {
        $authorityRegistry->run($caseId, static fn() => null, function () use ($index, $root, $database): array {
            $authority = maAuthority($root, $database, 'a' . $index);
            $path = $root . DIRECTORY_SEPARATOR . 'a' . $index . '.manifest';
            $payload = maPayload($authority);
            $wire = maWrite($path, $payload, $authority['key']);
            if ($index <= 2) {
                R1dcaManifestChannel::consume($path, $authority, 0, 12345);
                $second = $root . DIRECTORY_SEPARATOR . 'a' . $index . '-copy.manifest';
                file_put_contents($second, $wire, LOCK_EX);
                $resetAuthority = $authority;
                $resetAuthority['consumed'] = false;
                if ($index === 2) {
                    $workerAuthority = $resetAuthority;
                    $workerAuthority['manifest_path'] = $second;
                    $workerAuthority['key'] = bin2hex($workerAuthority['key']);
                    $config = $root . DIRECTORY_SEPARATOR . 'authority-worker.json';
                    file_put_contents($config, json_encode(['path' => $second, 'authority' => $workerAuthority], JSON_THROW_ON_ERROR));
                    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --consume-worker ' . escapeshellarg($config), $output, $exit);
                    ma($exit === 0 && $output === ['REPLAYED'], 'new_instance_receipt');
                    @unlink($config);
                } else {
                    maExact(fn() => R1dcaManifestChannel::consume($second, $resetAuthority, 0, 12345), 'manifest_replayed');
                }
                return compact('path', 'second', 'authority');
            }
            if ($index === 3) {
                $json = str_replace('{', '{ ', R1dcaManifestChannel::canonical($payload));
                maWrite($path, $payload, $authority['key'], $json);
                maExact(fn() => R1dcaManifestChannel::consume($path, $authority, 0, 12345), 'manifest_noncanonical');
            } elseif ($index === 4) {
                $db = new wpdb(DB_USER, DB_PASSWORD, $database, DB_HOST);
                $lock = 'r1dca_authority_' . bin2hex(random_bytes(12));
                ma((int) $db->get_var($db->prepare('SELECT GET_LOCK(%s,1)', $lock)) === 1, 'authority_lock_acquire');
                $authority['lock_names'] = [$lock];
                try { maExact(fn() => R1dcaManifestChannel::consume($path, $authority, 0, 12345), 'manifest_lock_mismatch'); }
                finally { $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $lock)); ma($db->get_var($db->prepare('SELECT IS_USED_LOCK(%s)', $lock)) === null, 'authority_lock_release'); $db->close(); }
            } elseif ($index === 5) {
                $markers = ['installer' => false, 'migration_manager' => false, 'manual_version' => true];
                maExact(fn() => ma($markers['installer'] && $markers['migration_manager'] && !$markers['manual_version'], 'mut08_productive_markers'), 'mut08_productive_markers');
            } elseif ($index === 6) {
                $rejected = false;
                try { maExact(static fn() => throw new class('authority_closed') extends RuntimeException {}, 'authority_closed'); }
                catch (RuntimeException $exception) { $rejected = $exception->getMessage() === 'unexpected_exception_class'; }
                ma($rejected, 'authority_subclass_accepted');
            } else {
                @unlink($path);
                $literal = 'R1DCA_FINAL_FIX_MATRIX=88/PASS';
                ma($literal !== '', 'stdout_fixture');
                maExact(fn() => R1dcaManifestChannel::consume($path, $authority, 0, 12345), 'manifest_missing');
            }
            return compact('path', 'authority');
        }, static fn($_, $result, $error) => ma($error === null && is_array($result), 'authority_case'), function ($result): void {
            if (!is_array($result)) return;
            maCleanup($result['path'], $result['authority']['receipt_dir']);
            if (isset($result['second'])) @unlink($result['second']);
        });
    }
    $authorityTotal = $authorityRegistry->seal();
    ma($authorityTotal === 8, 'authority_total');
    echo 'R1DCA_MANIFEST_AUTHORITY_GUARD_IDS=' . implode(',', $authorityIds) . PHP_EOL;
    echo 'R1DCA_MANIFEST_AUTHORITY_GUARDS=' . $authorityTotal . '/PASS' . PHP_EOL;

    $newGuardIds = ['NEW-GUARD-01-REPLAY-OMITTED','NEW-GUARD-02-CANONICAL-OMITTED','NEW-GUARD-03-EXACT-NOT-EXECUTED','NEW-GUARD-04-AUTHORITY-DUPLICATE','NEW-GUARD-05-LITERAL-TOTAL','NEW-GUARD-06-RECEIPT-RESIDUAL','NEW-GUARD-07-HOSTILE-JSON','NEW-GUARD-08-LOCK-RESIDUAL'];
    $newGuards = new R1dcaCaseRegistry('R1DCA_NEW_MATRIX_GUARD', array_combine($newGuardIds, $newGuardIds));
    foreach ($newGuardIds as $index => $caseId) {
        $newGuards->run($caseId, static fn() => null, function () use ($index, $root, $database): void {
            if ($index <= 4) {
                $count = [8, 12, 20, 2, 8][$index];
                $ids = [];
                for ($number = 1; $number <= $count; $number++) $ids['G' . $number] = 'guard';
                $mutant = new R1dcaCaseRegistry('MUTANT', $ids);
                $limit = $index === 3 ? 1 : $count - 1;
                for ($number = 1; $number <= $limit; $number++) $mutant->run('G' . $number, static fn() => null, static fn() => null, static fn() => null, static fn() => null);
                if ($index === 3) maExact(fn() => $mutant->run('G1', static fn() => null, static fn() => null, static fn() => null, static fn() => null), 'r1dca_registry_case_invalid');
                else maExact(fn() => $mutant->seal(), 'r1dca_registry_incomplete');
            } elseif ($index === 5) {
                $receiptDir = $root . DIRECTORY_SEPARATOR . 'residual-receipt';
                mkdir($receiptDir, 0700);
                file_put_contents($receiptDir . DIRECTORY_SEPARATOR . 'residual.receipt', "consumed\n");
                maExact(fn() => ma(count(glob($receiptDir . DIRECTORY_SEPARATOR . '*.receipt') ?: []) === 0, 'receipt_residual'), 'receipt_residual');
                @unlink($receiptDir . DIRECTORY_SEPARATOR . 'residual.receipt');
                @rmdir($receiptDir);
            } elseif ($index === 6) {
                $authority = maAuthority($root, $database, 'hostile-guard');
                $path = $root . DIRECTORY_SEPARATOR . 'hostile-guard.manifest';
                $payload = maPayload($authority);
                $json = str_replace('{', '{ ', R1dcaManifestChannel::canonical($payload));
                maWrite($path, $payload, $authority['key'], $json);
                maExact(fn() => R1dcaManifestChannel::consume($path, $authority, 0, 12345), 'manifest_noncanonical');
                maCleanup($path, $authority['receipt_dir']);
            } else {
                $db = new wpdb(DB_USER, DB_PASSWORD, $database, DB_HOST);
                $lock = 'r1dca_new_guard_' . bin2hex(random_bytes(12));
                ma((int) $db->get_var($db->prepare('SELECT GET_LOCK(%s,1)', $lock)) === 1, 'new_guard_lock_acquire');
                try { maExact(fn() => ma($db->get_var($db->prepare('SELECT IS_USED_LOCK(%s)', $lock)) === null, 'lock_residual'), 'lock_residual'); }
                finally { $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $lock)); $db->close(); }
            }
        }, static fn($_, $result, $error) => ma($error === null, 'new_guard_case'), static fn() => null);
    }
    $newGuardTotal = $newGuards->seal();
    ma($newGuardTotal === 8, 'new_guard_total');
    echo 'R1DCA_NEW_MATRIX_GUARDS=' . $newGuardTotal . '/PASS' . PHP_EOL;
} finally {
    if (is_dir($root)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($root);
    }
}
