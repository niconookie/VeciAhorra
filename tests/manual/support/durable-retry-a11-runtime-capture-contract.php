<?php

declare(strict_types=1);

namespace VeciAhorra\Tests\Manual\A11;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class DurableRetryA11CanonicalJson
{
    public const MAX_BYTES = 1048576;
    public const MAX_DEPTH = 8;

    public static function encode(array $value): string
    {
        $canonical = self::canonicalize($value, 1);
        $json = json_encode(
            $canonical,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (strlen($json) + 1 > self::MAX_BYTES) {
            throw new InvalidArgumentException('invalid_snapshot');
        }
        return $json;
    }

    public static function decodeEnvelope(string $line): array
    {
        if ($line === '' || strlen($line) > self::MAX_BYTES || !str_ends_with($line, "\n")) {
            throw new InvalidArgumentException('invalid_delta');
        }
        $json = substr($line, 0, -1);
        if ($json === '' || str_contains($json, "\n") || str_contains($json, "\r")) {
            throw new InvalidArgumentException('unexpected_child_output');
        }
        $value = json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new InvalidArgumentException('invalid_delta');
        }
        if (!hash_equals(self::encode($value), $json)) {
            throw new InvalidArgumentException('invalid_delta');
        }
        return $value;
    }

    public static function hash(array $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function canonicalize(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH || is_float($value) || is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('wrong_type');
        }
        if (is_string($value) && !preg_match('//u', $value)) {
            throw new InvalidArgumentException('wrong_type');
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => self::canonicalize($item, $depth + 1), $value);
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !preg_match('//u', $key)) {
                throw new InvalidArgumentException('wrong_type');
            }
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item, $depth + 1);
        }
        return $value;
    }
}

final class DurableRetryA11ActionMismatch extends RuntimeException
{
    public function __construct(
        public readonly string $caseId,
        public readonly string $actionPhase,
        public readonly string $port,
        public readonly int $expected,
        public readonly int $observed,
        public readonly string $reason
    ) {
        parent::__construct(sprintf(
            'actions_count_mismatch case=%s phase=%s port=%s expected=%d observed=%d reason=%s',
            $caseId, $actionPhase, $port, $expected, $observed, $reason
        ));
    }
}

final class DurableRetryA11ActionCapture
{
    public const PHASES = ['first_delivery', 'replay'];
    public const PORTS = [
        'webpay.commit',
        'woocommerce.payment_complete',
        'scheduler.action_schedule',
        'scheduler.action_cancel',
        'legacy.retry_schedule',
        'durable.worker_execute',
    ];
    public const MAX_COUNT = 2147483647;

    private array $counts;
    private array $sealedPhases = [];
    private bool $caseSealed = false;

    public function __construct(private readonly string $caseId)
    {
        $this->counts = self::zeroMap();
    }

    public static function zeroMap(): array
    {
        $ports = array_fill_keys(self::PORTS, 0);
        return ['first_delivery' => $ports, 'replay' => $ports];
    }

    public static function normalizeMap(array $counts): array
    {
        $phaseKeys = array_keys($counts);
        sort($phaseKeys, SORT_STRING);
        $expectedPhases = self::PHASES;
        sort($expectedPhases, SORT_STRING);
        if ($phaseKeys !== $expectedPhases) {
            throw new InvalidArgumentException('actions_phase_invalid');
        }
        $normalized = [];
        foreach (self::PHASES as $phase) {
            if (!is_array($counts[$phase]) || array_is_list($counts[$phase])) {
                throw new InvalidArgumentException('actions_map_invalid');
            }
            $portKeys = array_keys($counts[$phase]);
            sort($portKeys, SORT_STRING);
            $expectedPorts = self::PORTS;
            sort($expectedPorts, SORT_STRING);
            if ($portKeys !== $expectedPorts) {
                throw new InvalidArgumentException('actions_port_invalid');
            }
            $normalized[$phase] = [];
            foreach (self::PORTS as $port) {
                $value = $counts[$phase][$port];
                if (!is_int($value) || $value < 0 || $value > self::MAX_COUNT) {
                    throw new InvalidArgumentException('actions_count_invalid');
                }
                $normalized[$phase][$port] = $value;
            }
        }
        return $normalized;
    }

    public static function hashMap(array $counts): string
    {
        return DurableRetryA11CanonicalJson::hash(self::normalizeMap($counts));
    }

    public function counts(): array
    {
        return self::normalizeMap($this->counts);
    }

    public function hash(): string
    {
        return self::hashMap($this->counts);
    }

    public function increment(string $phase, string $port, mixed $delta): void
    {
        if ($this->caseSealed || isset($this->sealedPhases[$phase])) {
            throw new LogicException('actions_sealed');
        }
        self::assertPhase($phase);
        self::assertPort($port);
        if (!is_int($delta) || $delta <= 0) {
            throw new InvalidArgumentException('actions_delta_invalid');
        }
        $current = $this->counts[$phase][$port];
        if ($delta > self::MAX_COUNT - $current) {
            throw new InvalidArgumentException('actions_overflow');
        }
        $this->counts[$phase][$port] = $current + $delta;
    }

    public function sealPhase(string $phase): void
    {
        self::assertPhase($phase);
        if ($this->caseSealed || isset($this->sealedPhases[$phase])) {
            throw new LogicException('actions_sealed');
        }
        $this->sealedPhases[$phase] = true;
    }

    public function sealCase(): void
    {
        if ($this->caseSealed || array_keys($this->sealedPhases) !== self::PHASES) {
            throw new LogicException('actions_sealed');
        }
        $this->caseSealed = true;
    }

    public function assertExpected(array $expected): void
    {
        $expected = self::normalizeMap($expected);
        foreach (self::PHASES as $phase) {
            foreach (self::PORTS as $port) {
                $want = $expected[$phase][$port];
                $have = $this->counts[$phase][$port];
                if ($want !== $have) {
                    throw new DurableRetryA11ActionMismatch(
                        $this->caseId, $phase, $port, $want, $have,
                        $have < $want ? 'observed_lower' : 'observed_higher'
                    );
                }
            }
        }
    }

    private static function assertPhase(string $phase): void
    {
        if (!in_array($phase, self::PHASES, true)) {
            throw new InvalidArgumentException('actions_phase_invalid');
        }
    }

    private static function assertPort(string $port): void
    {
        if (!in_array($port, self::PORTS, true)) {
            throw new InvalidArgumentException('actions_port_invalid');
        }
    }
}

final class DurableRetryA11CapturePlan
{
    public const TYPES = ['positive-int', 'non-empty-string', 'utc-second-timestamp', 'sha256-lowercase-hex', 'boolean'];
    public const SOURCE_PHASES = ['setup', 'first_delivery', 'replay'];
    public const REQUIRED_BEFORE = ['first_delivery', 'replay', 'assertions_finales', 'cleanup'];
    public const CARDINALITIES = ['exactly-zero', 'exactly-one', 'exactly-N', 'zero-or-one', 'one-or-more'];
    public const EQUALITY = ['none', 'same-on-replay'];
    public const FIXTURE_ID_KEYS = [
        'orders', 'checkouts', 'checkout_orders', 'payment_sessions', 'payment_origin_contexts',
        'webpay_returns', 'payment_reconciliations', 'durable_retry_schedules',
        'business_completions', 'business_completion_orders', 'payments', 'payment_orders',
        'delivery_completions', 'fulfillment_completions', 'action_scheduler_actions',
    ];
    private const ALIAS_PATTERN = '/^A11-(?:OP|CON|CR|WR|EX)-[0-9]{2}\.[a-z][a-z0-9_]{0,31}\.[a-z][a-z0-9_]{0,47}(?:\.(?:0|[1-9][0-9]*|[a-z][a-z0-9_]{0,31}))?$/D';
    private const CASE_PATTERN = '/^A11-(?:OP|CON|CR|WR|EX)-[0-9]{2}$/D';
    private const SOURCE_PATTERN = '/^[a-z][a-z0-9_.:-]{0,127}$/D';
    private const EXACT_FIELDS = ['type', 'owner', 'source_phase', 'source', 'cardinality', 'required_before', 'immutable', 'equality', 'cleanup'];

    private array $entries;
    private array $fixtureIdPlan;
    private array $businessIdentifiers;
    private string $hash;

    public function __construct(
        private readonly string $caseId,
        array $entries,
        array $fixtureIdPlan,
        array $businessIdentifiers = []
    ) {
        if (!preg_match(self::CASE_PATTERN, $caseId)) {
            throw new InvalidArgumentException('wrong_owner');
        }
        $this->entries = $this->validateEntries($entries);
        $this->fixtureIdPlan = $this->validateFixtureIdPlan($fixtureIdPlan);
        $this->businessIdentifiers = $this->validateBusinessIdentifiers($businessIdentifiers);
        $this->hash = DurableRetryA11CanonicalJson::hash($this->toArray());
    }

    public function caseId(): string { return $this->caseId; }
    public function hash(): string { return $this->hash; }
    public function entries(): array { return $this->entries; }
    public function fixtureIdPlan(): array { return $this->fixtureIdPlan; }
    public function businessIdentifiers(): array { return $this->businessIdentifiers; }
    public function has(string $alias): bool { return array_key_exists($alias, $this->entries); }

    public function entry(string $alias): array
    {
        if (!$this->has($alias)) {
            throw new InvalidArgumentException('unknown_alias');
        }
        return $this->entries[$alias];
    }

    public function assertValue(string $type, mixed $value): void
    {
        $valid = match ($type) {
            'positive-int' => is_int($value) && $value > 0,
            'non-empty-string' => is_string($value) && $value !== '' && strlen($value) <= 1024
                && !str_contains($value, "\0") && preg_match('//u', $value) === 1,
            'utc-second-timestamp' => self::validTimestamp($value),
            'sha256-lowercase-hex' => is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1,
            'boolean' => is_bool($value),
            default => false,
        };
        if (!$valid) {
            throw new InvalidArgumentException('wrong_type');
        }
    }

    public function toArray(): array
    {
        return [
            'business_identifiers' => $this->businessIdentifiers,
            'capture_plan' => $this->entries,
            'case_id' => $this->caseId,
            'fixture_id_plan' => $this->fixtureIdPlan,
            'schema' => 'veciahorra-a11-static/v2',
        ];
    }

    private function validateEntries(array $entries): array
    {
        ksort($entries, SORT_STRING);
        foreach ($entries as $alias => $entry) {
            if (!is_string($alias) || strlen($alias) > 128 || !preg_match(self::ALIAS_PATTERN, $alias)) {
                throw new InvalidArgumentException('unknown_alias');
            }
            if (!str_starts_with($alias, $this->caseId . '.')) {
                throw new InvalidArgumentException('wrong_owner');
            }
            $parts = explode('.', $alias);
            if (in_array($parts[1], ['system', 'coordinator', 'snapshot'], true) || str_starts_with($parts[2], '_')) {
                throw new InvalidArgumentException('unknown_alias');
            }
            if (!is_array($entry)) {
                throw new InvalidArgumentException('invalid_snapshot');
            }
            $expected = self::EXACT_FIELDS;
            if (($entry['cardinality'] ?? null) === 'exactly-N') {
                $expected[] = 'count';
            }
            $keys = array_keys($entry); sort($keys); sort($expected);
            if ($keys !== $expected) {
                throw new InvalidArgumentException('invalid_snapshot');
            }
            if (($entry['owner'] ?? null) !== $this->caseId) throw new InvalidArgumentException('wrong_owner');
            if (!in_array($entry['type'], self::TYPES, true)) throw new InvalidArgumentException('wrong_type');
            if (!in_array($entry['source_phase'], self::SOURCE_PHASES, true)) throw new InvalidArgumentException('wrong_phase');
            if (!is_string($entry['source']) || !preg_match(self::SOURCE_PATTERN, $entry['source'])) throw new InvalidArgumentException('invalid_snapshot');
            if (!in_array($entry['cardinality'], self::CARDINALITIES, true)) throw new InvalidArgumentException('cardinality_mismatch');
            if (!in_array($entry['required_before'], self::REQUIRED_BEFORE, true)) throw new InvalidArgumentException('wrong_phase');
            if ($entry['immutable'] !== true || !is_bool($entry['cleanup']) || !in_array($entry['equality'], self::EQUALITY, true)) {
                throw new InvalidArgumentException('invalid_snapshot');
            }
            if (isset($entry['count']) && (!is_int($entry['count']) || $entry['count'] < 1)) {
                throw new InvalidArgumentException('cardinality_mismatch');
            }
        }
        return $entries;
    }

    private function validateFixtureIdPlan(array $plan): array
    {
        if (array_keys($plan) !== self::FIXTURE_ID_KEYS) {
            throw new InvalidArgumentException('cardinality_mismatch');
        }
        $seen = [];
        foreach ($plan as $aliases) {
            if (!is_array($aliases) || !array_is_list($aliases)) throw new InvalidArgumentException('cardinality_mismatch');
            foreach ($aliases as $alias) {
                if (!is_string($alias) || !$this->has($alias)) throw new InvalidArgumentException('unknown_alias');
                if (($this->entries[$alias]['type'] ?? null) !== 'positive-int' || isset($seen[$alias])) {
                    throw new InvalidArgumentException('cardinality_mismatch');
                }
                $seen[$alias] = true;
            }
        }
        return $plan;
    }

    private function validateBusinessIdentifiers(array $identifiers): array
    {
        ksort($identifiers, SORT_STRING);
        foreach ($identifiers as $name => $spec) {
            if (!is_string($name) || !preg_match('/^(buy_order|session_id|token|transaction_reference)$/D', $name)
                || !is_array($spec) || array_keys($spec) !== ['type', 'value']
                || $spec['type'] !== 'non-empty-string') {
                throw new InvalidArgumentException('wrong_type');
            }
            $this->assertValue('non-empty-string', $spec['value']);
            if (!str_contains($spec['value'], $this->caseId)) throw new InvalidArgumentException('wrong_owner');
        }
        return $identifiers;
    }

    private static function validTimestamp(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value;
    }
}

final class DurableRetryA11RuntimeCaptureStore
{
    private const SNAPSHOT_PHASES = ['bootstrap' => 'S0', 'setup' => 'S1', 'first_delivery' => 'S2', 'replay' => 'S3', 'assertions_finales' => 'S4'];
    private const NEXT_PHASE = ['bootstrap' => 'setup', 'setup' => 'first_delivery', 'first_delivery' => 'replay', 'replay' => 'assertions_finales', 'assertions_finales' => 'cleanup', 'cleanup' => 'completed'];
    private array $captures = [];
    private array $snapshots = [];
    private string $phase = 'bootstrap';
    private bool $discarded = false;
    private string $cleanupStatus = 'pending';
    private DurableRetryA11ActionCapture $actions;

    public function __construct(private readonly string $executionId, private readonly DurableRetryA11CapturePlan $plan)
    {
        if (!preg_match('/^a11_[0-9]{14}_[1-9][0-9]*_[a-f0-9]{16}$/D', $executionId)) {
            throw new InvalidArgumentException('wrong_owner');
        }
        $this->actions = new DurableRetryA11ActionCapture($plan->caseId());
        $this->sealSnapshot('bootstrap');
        $this->phase = 'setup';
    }

    public function executionId(): string { return $this->executionId; }
    public function phase(): string { return $this->phase; }
    public function plan(): DurableRetryA11CapturePlan { return $this->plan; }
    public function currentSnapshot(): array
    {
        $this->assertActive();
        $name = array_key_last($this->snapshots);
        if (!is_string($name)) throw new LogicException('wrong_phase');
        return $this->snapshot($name);
    }
    public function history(): array { return array_map(fn (array $s): array => self::copy($s), $this->snapshots); }

    public function actionCounts(): array { $this->assertActive(); return $this->actions->counts(); }
    public function actionHash(): string { $this->assertActive(); return $this->actions->hash(); }

    public function recordAction(string $caseId, string $ownershipToken, string $phase, string $port, mixed $delta): void
    {
        $this->assertActive();
        if ($caseId !== $this->plan->caseId() || !hash_equals($this->executionId, $ownershipToken)) {
            throw new InvalidArgumentException('wrong_owner');
        }
        if ($phase !== $this->phase) {
            throw new InvalidArgumentException('wrong_phase');
        }
        $this->actions->increment($phase, $port, $delta);
    }

    public function integrateActionDelta(array $delta): array
    {
        $this->assertActive();
        DurableRetryA11TransportEnvelopeValidator::assertActionDelta(
            $delta, $this->plan, $this->executionId, $this->phase, $this->actions->hash()
        );
        $this->recordAction(
            $delta['case_id'], $delta['ownership_token'], $delta['phase'], $delta['port'], $delta['delta']
        );
        return $this->actions->counts();
    }

    public function assertExpectedActions(array $expected): void
    {
        $this->assertActive();
        $this->actions->assertExpected($expected);
    }

    public function capture(string $alias, mixed $value, string $source, string $phase): void
    {
        $this->assertActive();
        $entry = $this->plan->entry($alias);
        if ($entry['owner'] !== $this->plan->caseId()) throw new InvalidArgumentException('wrong_owner');
        $this->plan->assertValue($entry['type'], $value);
        if (isset($this->captures[$alias])) {
            $old = $this->captures[$alias];
            if ($old['value'] !== $value || $old['type'] !== $entry['type'] || $old['source'] !== $source || $old['owner'] !== $entry['owner']) {
                throw new InvalidArgumentException('duplicate_capture_conflict');
            }
        }
        if ($phase !== $this->phase || $entry['source_phase'] !== $phase) throw new InvalidArgumentException('wrong_phase');
        if ($entry['source'] !== $source) throw new InvalidArgumentException('invalid_delta');
        if (isset($this->captures[$alias])) return;
        $this->captures[$alias] = ['type' => $entry['type'], 'value' => $value, 'owner' => $entry['owner'], 'source' => $source, 'source_phase' => $phase];
        ksort($this->captures, SORT_STRING);
    }

    public function resolve(string $alias): mixed
    {
        $this->assertActive();
        $this->plan->entry($alias);
        if (!isset($this->captures[$alias])) throw new LogicException('missing_capture');
        return self::copyValue($this->captures[$alias]['value']);
    }

    public function assertSameCapture(string $alias, mixed $observed): void
    {
        $entry = $this->plan->entry($alias);
        $this->plan->assertValue($entry['type'], $observed);
        if ($this->resolve($alias) !== $observed) throw new InvalidArgumentException('duplicate_capture_conflict');
    }

    public function integrateDelta(array $delta): array
    {
        $this->assertActive();
        DurableRetryA11TransportEnvelopeValidator::assertDelta($delta, $this->plan, $this->executionId, $this->phase, $this->currentSnapshot()['snapshot_hash']);
        $pending = $this->captures;
        try {
            foreach ($delta['captures'] as $alias => $capture) {
                $this->capture($alias, $capture['value'], $capture['source'], $this->phase);
            }
            $this->assertRequiredBefore(self::NEXT_PHASE[$this->phase]);
            $sealedPhase = $this->phase;
            if (in_array($sealedPhase, DurableRetryA11ActionCapture::PHASES, true)) {
                $this->actions->sealPhase($sealedPhase);
                if ($sealedPhase === 'replay') {
                    $this->actions->sealCase();
                }
            }
            $snapshot = $this->sealSnapshot($sealedPhase);
            $this->phase = self::NEXT_PHASE[$sealedPhase];
            return $snapshot;
        } catch (\Throwable $error) {
            $this->captures = $pending;
            throw $error;
        }
    }

    public function requestEnvelope(int $timeoutSeconds): array
    {
        $this->assertActive();
        if (!in_array($this->phase, ['setup', 'first_delivery', 'replay', 'assertions_finales', 'cleanup'], true)
            || $timeoutSeconds < 1 || $timeoutSeconds > 30) throw new InvalidArgumentException('wrong_phase');
        return [
            'schema' => 'veciahorra-a11-capture/v1', 'kind' => 'phase_request',
            'case_id' => $this->plan->caseId(), 'execution_id' => $this->executionId,
            'phase' => $this->phase, 'timeout_seconds' => $timeoutSeconds,
            'capture_plan' => $this->plan->toArray(), 'input_snapshot' => $this->currentSnapshot(),
        ];
    }

    public function resolvedFixtureIds(): array
    {
        $this->assertActive();
        $resolved = [];
        $seenByKey = [];
        foreach ($this->plan->fixtureIdPlan() as $key => $aliases) {
            $resolved[$key] = [];
            foreach ($aliases as $alias) {
                $value = $this->resolve($alias);
                if (!is_int($value) || $value < 1 || isset($seenByKey[$key][$value])) throw new InvalidArgumentException('cardinality_mismatch');
                $seenByKey[$key][$value] = true;
                $resolved[$key][] = $value;
            }
        }
        return $resolved;
    }

    public function finishCleanup(bool $successful): void
    {
        if ($this->discarded || $this->phase !== 'cleanup') throw new LogicException('wrong_phase');
        $this->cleanupStatus = $successful ? 'clean' : 'cleanup_incomplete';
        if (!$successful) throw new RuntimeException('cleanup_incomplete');
        $this->captures = []; $this->snapshots = []; $this->discarded = true; $this->phase = 'completed';
    }

    public function cleanupStatus(): string { return $this->cleanupStatus; }

    public function snapshot(string $name): array
    {
        $this->assertActive();
        if (!isset($this->snapshots[$name])) throw new InvalidArgumentException('invalid_snapshot');
        return self::copy($this->snapshots[$name]);
    }

    private function sealSnapshot(string $phase): array
    {
        $name = self::SNAPSHOT_PHASES[$phase] ?? null;
        if ($name === null || isset($this->snapshots[$name])) throw new LogicException('wrong_phase');
        $previous = $this->snapshots === [] ? null : $this->snapshots[array_key_last($this->snapshots)]['snapshot_hash'];
        $snapshot = [
            'schema' => 'veciahorra-a11-capture/v1', 'kind' => 'capture_snapshot',
            'case_id' => $this->plan->caseId(), 'execution_id' => $this->executionId,
            'snapshot_name' => $name, 'phase' => $phase, 'plan_hash' => $this->plan->hash(),
            'previous_snapshot_hash' => $previous, 'actions' => $this->actions->counts(),
            'captures' => $this->captures, 'sealed' => true,
        ];
        $snapshot['snapshot_hash'] = DurableRetryA11CanonicalJson::hash($snapshot);
        $this->snapshots[$name] = $snapshot;
        return self::copy($snapshot);
    }

    private function assertRequiredBefore(string $nextPhase): void
    {
        $order = array_flip(['first_delivery', 'replay', 'assertions_finales', 'cleanup']);
        foreach ($this->plan->entries() as $alias => $entry) {
            if (($order[$entry['required_before']] ?? 99) <= ($order[$nextPhase] ?? 99)
                && $entry['cardinality'] !== 'exactly-zero' && !isset($this->captures[$alias])) {
                throw new InvalidArgumentException('cardinality_mismatch');
            }
        }
    }

    private function assertActive(): void { if ($this->discarded || $this->phase === 'completed') throw new LogicException('wrong_phase'); }
    private static function copy(array $value): array { return json_decode(DurableRetryA11CanonicalJson::encode($value), true, DurableRetryA11CanonicalJson::MAX_DEPTH, JSON_THROW_ON_ERROR); }
    private static function copyValue(mixed $value): mixed { return is_array($value) ? self::copy($value) : $value; }
}

final class DurableRetryA11TransportEnvelopeValidator
{
    public static function assertActionDelta(
        array $delta,
        DurableRetryA11CapturePlan $plan,
        string $executionId,
        string $phase,
        string $baseActionHash
    ): void {
        $keys = array_keys($delta); sort($keys);
        $expected = ['schema', 'kind', 'case_id', 'ownership_token', 'phase', 'port', 'delta', 'base_action_hash'];
        sort($expected);
        if ($keys !== $expected || ($delta['schema'] ?? null) !== 'veciahorra-a11-capture/v1'
            || ($delta['kind'] ?? null) !== 'action_delta'
            || ($delta['case_id'] ?? null) !== $plan->caseId()
            || !is_string($delta['ownership_token'] ?? null)
            || !hash_equals($executionId, $delta['ownership_token'])) {
            throw new InvalidArgumentException('wrong_owner');
        }
        if (($delta['phase'] ?? null) !== $phase) throw new InvalidArgumentException('wrong_phase');
        if (!in_array($phase, DurableRetryA11ActionCapture::PHASES, true)) throw new InvalidArgumentException('actions_phase_invalid');
        if (!is_string($delta['port']) || !in_array($delta['port'], DurableRetryA11ActionCapture::PORTS, true)) {
            throw new InvalidArgumentException('actions_port_invalid');
        }
        if (!is_int($delta['delta']) || $delta['delta'] <= 0) throw new InvalidArgumentException('actions_delta_invalid');
        if (!is_string($delta['base_action_hash']) || !hash_equals($baseActionHash, $delta['base_action_hash'])) {
            throw new InvalidArgumentException('actions_base_hash_mismatch');
        }
        DurableRetryA11CanonicalJson::encode($delta);
    }

    public static function assertDelta(array $delta, DurableRetryA11CapturePlan $plan, string $executionId, string $phase, string $baseHash): void
    {
        $keys = array_keys($delta); sort($keys);
        $expected = ['schema', 'kind', 'case_id', 'execution_id', 'phase', 'base_snapshot_hash', 'captures']; sort($expected);
        if ($keys !== $expected || ($delta['schema'] ?? null) !== 'veciahorra-a11-capture/v1'
            || ($delta['kind'] ?? null) !== 'capture_delta' || ($delta['case_id'] ?? null) !== $plan->caseId()
            || ($delta['execution_id'] ?? null) !== $executionId) throw new InvalidArgumentException('wrong_owner');
        if (($delta['phase'] ?? null) !== $phase) throw new InvalidArgumentException('wrong_phase');
        if (!is_string($delta['base_snapshot_hash']) || !hash_equals($baseHash, $delta['base_snapshot_hash'])) throw new InvalidArgumentException('base_hash_mismatch');
        if (!is_array($delta['captures']) || array_is_list($delta['captures']) && $delta['captures'] !== []) throw new InvalidArgumentException('invalid_delta');
        $sorted = $delta['captures']; ksort($sorted, SORT_STRING);
        if ($sorted !== $delta['captures']) throw new InvalidArgumentException('invalid_delta');
        foreach ($delta['captures'] as $alias => $capture) {
            $captureKeys = is_array($capture) ? array_keys($capture) : [];
            sort($captureKeys);
            if (!is_string($alias) || !$plan->has($alias) || !is_array($capture) || $captureKeys !== ['source', 'type', 'value']) throw new InvalidArgumentException('invalid_delta');
            $entry = $plan->entry($alias);
            if ($capture['type'] !== $entry['type']) throw new InvalidArgumentException('wrong_type');
            if ($capture['source'] !== $entry['source']) throw new InvalidArgumentException('invalid_delta');
            $plan->assertValue($entry['type'], $capture['value']);
        }
        DurableRetryA11CanonicalJson::encode($delta);
    }
}
