<?php

declare(strict_types=1);

namespace VeciAhorra\Tests\Manual\A11;

use InvalidArgumentException;
use RuntimeException;

require_once __DIR__ . '/durable-retry-a11-runtime-capture-contract.php';

final class DurableRetryA11Invocation
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $entrypoint,
        public readonly int $timeoutSeconds = 30
    ) {}
}

final class DurableRetryA11ProcessResult
{
    public function __construct(
        public readonly string $phase,
        public readonly string $caseId,
        public readonly int $pid,
        public readonly string $startedAt,
        public readonly string $finishedAt,
        public readonly int $durationMilliseconds,
        public readonly int $exitCode,
        public readonly bool $timedOut,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly array $envelope,
        public readonly string $cleanupStatus
    ) {}
}

final class DurableRetryA11Coordinator
{
    public const ERROR_CODES = [
        'unknown_alias', 'wrong_owner', 'wrong_phase', 'wrong_type', 'missing_capture',
        'duplicate_capture_conflict', 'cardinality_mismatch', 'base_hash_mismatch',
        'invalid_snapshot', 'invalid_delta', 'unexpected_child_output', 'cleanup_incomplete',
        'actions_phase_invalid', 'actions_port_invalid', 'actions_map_invalid',
        'actions_count_invalid', 'actions_delta_invalid', 'actions_overflow',
        'actions_sealed', 'actions_base_hash_mismatch', 'actions_count_mismatch',
    ];
    private const EXIT_INPUT = 64;
    private const EXIT_DATA = 65;
    private const EXIT_INTERNAL = 70;
    private const EXIT_TRANSIENT = 75;
    private const EXIT_TIMEOUT = 124;
    private const MAX_TIMEOUT = 30;
    /** @var array<string, DurableRetryA11RuntimeCaptureStore> */
    private array $stores = [];
    /** @var array<int, resource> */
    private array $processes = [];
    private int $transportAttempts = 0;

    public function __construct(private readonly string $phpBinary)
    {
        if ($phpBinary === '' || !is_file($phpBinary)) {
            throw new InvalidArgumentException('invalid_snapshot');
        }
    }

    public function bootstrap(
        string $executionId,
        string $caseId,
        array $capturePlan,
        array $fixtureIdPlan,
        array $businessIdentifiers = []
    ): array {
        if (isset($this->stores[$executionId])) {
            throw new InvalidArgumentException('wrong_owner');
        }
        $plan = new DurableRetryA11CapturePlan($caseId, $capturePlan, $fixtureIdPlan, $businessIdentifiers);
        $store = new DurableRetryA11RuntimeCaptureStore($executionId, $plan);
        $this->stores[$executionId] = $store;
        return $store->snapshot('S0');
    }

    public function store(string $executionId): DurableRetryA11RuntimeCaptureStore
    {
        if (!isset($this->stores[$executionId])) {
            throw new InvalidArgumentException('wrong_owner');
        }
        return $this->stores[$executionId];
    }

    public function recordAction(
        string $executionId,
        string $caseId,
        string $ownershipToken,
        string $phase,
        string $port,
        mixed $delta
    ): array {
        $store = $this->store($executionId);
        $store->recordAction($caseId, $ownershipToken, $phase, $port, $delta);
        return $store->actionCounts();
    }

    public function ingestActionDelta(string $executionId, array $delta): array
    {
        return $this->store($executionId)->integrateActionDelta($delta);
    }

    public function observedActions(string $executionId): array
    {
        return $this->store($executionId)->actionCounts();
    }

    public function assertExpectedActions(string $executionId, array $expected): void
    {
        $this->store($executionId)->assertExpectedActions($expected);
    }

    public function runPhase(DurableRetryA11Invocation $invocation): DurableRetryA11ProcessResult
    {
        $store = $this->store($invocation->executionId);
        if ($store->phase() === 'cleanup') {
            return $this->runCleanup($invocation, $store);
        }
        $result = $this->invoke($invocation, $store->requestEnvelope($invocation->timeoutSeconds));
        $snapshot = $store->integrateDelta($result['envelope']);
        return new DurableRetryA11ProcessResult(
            $result['phase'], $store->plan()->caseId(), $result['pid'], $result['started_at'],
            $result['finished_at'], $result['duration_ms'], $result['exit_code'], false,
            $result['stdout'], $result['stderr'], $snapshot, $store->cleanupStatus()
        );
    }

    public function finishCleanup(string $executionId, bool $successful): void
    {
        $store = $this->store($executionId);
        $store->finishCleanup($successful);
        if ($successful) {
            unset($this->stores[$executionId]);
        }
    }

    public function activeProcesses(): array { return array_keys($this->processes); }
    public function transportAttempts(): int { return $this->transportAttempts; }
    public function hasExecution(string $executionId): bool { return isset($this->stores[$executionId]); }

    public function terminateAll(): void
    {
        foreach ($this->processes as $pid => $process) {
            if (is_resource($process)) {
                @proc_terminate($process, 9);
                @proc_close($process);
            }
            unset($this->processes[$pid]);
        }
    }

    private function runCleanup(DurableRetryA11Invocation $invocation, DurableRetryA11RuntimeCaptureStore $store): DurableRetryA11ProcessResult
    {
        $result = $this->invoke($invocation, $store->requestEnvelope($invocation->timeoutSeconds), 'cleanup_result');
        $envelope = $result['envelope'];
        $keys = array_keys($envelope); sort($keys);
        $expected = ['schema', 'kind', 'case_id', 'execution_id', 'phase', 'base_snapshot_hash', 'status']; sort($expected);
        $base = $store->currentSnapshot()['snapshot_hash'];
        $valid = $keys === $expected
            && ($envelope['schema'] ?? null) === 'veciahorra-a11-capture/v1'
            && ($envelope['kind'] ?? null) === 'cleanup_result'
            && ($envelope['case_id'] ?? null) === $store->plan()->caseId()
            && ($envelope['execution_id'] ?? null) === $invocation->executionId
            && ($envelope['phase'] ?? null) === 'cleanup'
            && is_string($envelope['base_snapshot_hash'] ?? null)
            && hash_equals($base, $envelope['base_snapshot_hash'])
            && ($envelope['status'] ?? null) === 'clean';
        if (!$valid) {
            throw new RuntimeException('cleanup_incomplete');
        }
        $store->finishCleanup(true);
        unset($this->stores[$invocation->executionId]);
        return new DurableRetryA11ProcessResult(
            'cleanup', $store->plan()->caseId(), $result['pid'], $result['started_at'],
            $result['finished_at'], $result['duration_ms'], $result['exit_code'], false,
            $result['stdout'], $result['stderr'], $envelope, 'clean'
        );
    }

    private function invoke(DurableRetryA11Invocation $invocation, array $request, string $expectedKind = 'capture_delta'): array
    {
        if ($invocation->timeoutSeconds < 1 || $invocation->timeoutSeconds > self::MAX_TIMEOUT
            || !is_file($invocation->entrypoint)) {
            throw new InvalidArgumentException('invalid_snapshot');
        }
        $this->transportAttempts++;
        $command = [$this->phpBinary, $invocation->entrypoint, '--a11-capture-child'];
        $pipes = [];
        $startedNs = hrtime(true);
        $startedAt = self::now();
        $process = proc_open($command, [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, dirname($invocation->entrypoint));
        if (!is_resource($process)) {
            throw new RuntimeException('invalid_snapshot');
        }
        $status = proc_get_status($process);
        $pid = (int)$status['pid'];
        $this->processes[$pid] = $process;
        $requestLine = DurableRetryA11CanonicalJson::encode($request) . "\n";
        $written = fwrite($pipes[0], $requestLine);
        fclose($pipes[0]);
        if ($written !== strlen($requestLine)) {
            $this->terminateProcess($pid, $process, $pipes);
            throw new RuntimeException('invalid_snapshot');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = ''; $stderr = ''; $timedOut = false; $lastExit = null;
        $deadline = $startedNs + ($invocation->timeoutSeconds * 1_000_000_000);
        while (true) {
            $stdout .= $this->readAvailable($pipes[1]);
            $stderr .= $this->readAvailable($pipes[2]);
            if (strlen($stdout) > DurableRetryA11CanonicalJson::MAX_BYTES || strlen($stderr) > DurableRetryA11CanonicalJson::MAX_BYTES) {
                $this->terminateProcess($pid, $process, $pipes);
                throw new RuntimeException('unexpected_child_output');
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $lastExit = $status['exitcode'];
                break;
            }
            if (hrtime(true) >= $deadline) {
                $timedOut = true; @proc_terminate($process, 9); break;
            }
            usleep(10000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $closedExit = proc_close($process);
        unset($this->processes[$pid]);
        $exitCode = $timedOut ? self::EXIT_TIMEOUT : (is_int($lastExit) && $lastExit >= 0 ? $lastExit : $closedExit);
        if ($timedOut) {
            throw new RuntimeException('timeout');
        }
        if ($exitCode !== 0 || $stderr !== '') {
            throw new RuntimeException($stderr !== '' ? 'unexpected_child_output' : 'invalid_delta');
        }
        try {
            $envelope = DurableRetryA11CanonicalJson::decodeEnvelope($stdout);
        } catch (\Throwable $error) {
            throw new RuntimeException('unexpected_child_output', 0, $error);
        }
        if (($envelope['kind'] ?? null) !== $expectedKind) {
            throw new RuntimeException('invalid_delta');
        }
        return [
            'phase' => (string)($request['phase'] ?? ''), 'pid' => $pid,
            'started_at' => $startedAt, 'finished_at' => self::now(),
            'duration_ms' => (int)((hrtime(true) - $startedNs) / 1_000_000),
            'exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr,
            'envelope' => $envelope,
        ];
    }

    private function terminateProcess(int $pid, mixed $process, array $pipes): void
    {
        foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
        if (is_resource($process)) { @proc_terminate($process, 9); @proc_close($process); }
        unset($this->processes[$pid]);
    }

    private function readAvailable(mixed $pipe): string
    {
        if (!is_resource($pipe)) return '';
        $meta = stream_get_meta_data($pipe);
        $bytes = (int)($meta['unread_bytes'] ?? 0);
        return $bytes > 0 ? (string)fread($pipe, $bytes) : '';
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s.') . sprintf('%06d', (int)((microtime(true) - (int)microtime(true)) * 1_000_000)) . 'Z';
    }
}
