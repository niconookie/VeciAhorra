<?php

declare(strict_types=1);

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
foreach ([
    'DB_USER' => 'test',
    'DB_PASSWORD' => 'test',
    'DB_NAME' => 'test',
    'DB_HOST' => 'test',
] as $constant => $value) {
    if (! defined($constant)) {
        define($constant, $value);
    }
}
if (! class_exists('wpdb')) {
    class wpdb
    {
        private static ?self $current = null;
        /** @var list<self> */
        private static array $instances = [];
        public string $prefix = 'custom_';
        public string $last_error = '';
        public array $events = [];
        public array $functionalRows = [];
        public array $durableRows = [];
        public ?array $externalDurableRows = null;
        public bool $failStart = false;
        public bool $failInsert = false;
        public bool $failCommit = false;
        public bool $throwInsert = false;
        public bool $throwCommit = false;
        public bool $failRollback = false;
        public bool $throwRollback = false;
        public bool $failExternalConnection = false;
        public bool $failFunctionalRead = false;
        public bool $throwInsertPrepare = false;
        public int $failDurableReadAt = 0;
        private int $durableReadCount = 0;
        private array $preparedValues = [];
        private ?array $insertedRow = null;

        public function __construct(mixed ...$credentials)
        {
            if ($credentials === []) {
                self::$current = $this;
                self::$instances = [$this];

                return;
            }
            if (self::$current instanceof self) {
                if (self::$current->failExternalConnection) {
                    throw new RuntimeException('safe simulated connection failure');
                }
                $this->durableRows = self::$current->externalDurableRows
                    ?? self::$current->durableRows;
                $this->insertedRow = self::$current->externalDurableRows === null
                    ? self::$current->insertedRow
                    : null;
                $this->failDurableReadAt = self::$current->failDurableReadAt;
                $this->durableReadCount = self::$current->durableReadCount;
            }
            self::$instances[] = $this;
        }

        /** @return list<self> */
        public static function instances(): array
        {
            return self::$instances;
        }

        public function prepare(string $sql, mixed ...$values): string
        {
            if ($this->throwInsertPrepare && str_starts_with($sql, 'INSERT INTO')) {
                throw new RuntimeException('safe simulated pre-write failure');
            }
            $this->preparedValues = $values;
            $this->events[] = ['prepare', $sql, $values];

            return $sql;
        }

        public function close(): true
        {
            $this->events[] = ['close', '', []];

            return true;
        }

        public function get_row(string $sql, mixed $format): ?array
        {
            $this->events[] = ['read', $sql, $this->preparedValues];
            if (str_contains($sql, 'payment_reconciliations')) {
                if ($this->failFunctionalRead) {
                    $this->last_error = 'safe simulated failure';

                    return null;
                }
                return array_shift($this->functionalRows);
            }
            ++$this->durableReadCount;
            if ($this->failDurableReadAt === $this->durableReadCount) {
                $this->last_error = 'safe simulated failure';

                return null;
            }
            $this->last_error = '';

            return array_shift($this->durableRows) ?? $this->insertedRow;
        }

        public function get_results(string $sql, mixed $format): ?array
        {
            $row = $this->get_row($sql, $format);

            if (isset($row['_batch']) && is_array($row['_batch'])) {
                return array_map([$this, 'withEvidenceCounts'], $row['_batch']);
            }

            return $row === null ? [] : [$this->withEvidenceCounts($row)];
        }

        private function withEvidenceCounts(array $row): array
        {
            $row['evidence_public_id_count'] = $row['evidence_public_id_count'] ?? '1';
            $row['evidence_token_hash_count'] = $row['evidence_token_hash_count'] ?? '1';

            return $row;
        }

        public function query(string $sql): int|false
        {
            $this->events[] = ['query', $sql, $this->preparedValues];
            if ($sql === 'START TRANSACTION') {
                return $this->failStart ? false : 0;
            }
            if ($sql === 'COMMIT') {
                if ($this->throwCommit) {
                    throw new RuntimeException('safe simulated commit failure');
                }
                return $this->failCommit ? false : 0;
            }
            if ($sql === 'ROLLBACK') {
                if ($this->throwRollback) {
                    throw new RuntimeException('safe simulated rollback failure');
                }

                return $this->failRollback ? false : 0;
            }
            if (str_starts_with($sql, 'INSERT INTO')) {
                if ($this->throwInsert) {
                    throw new RuntimeException('safe simulated insert failure');
                }
                if ($this->failInsert) {
                    return false;
                }
                preg_match('/\(([^)]+)\) VALUES/', $sql, $match);
                $columns = array_map('trim', explode(',', $match[1]));
                $values = $this->preparedValues;
                $offset = 0;
                $row = ['id' => '9001'];
                preg_match('/VALUES \(([^)]+)\)/', $sql, $tokensMatch);
                $tokens = array_map('trim', explode(',', $tokensMatch[1]));
                foreach ($columns as $index => $column) {
                    $row[$column] = $tokens[$index] === 'NULL'
                        ? null
                        : (string) $values[$offset++];
                }
                $this->insertedRow = $row;

                return 1;
            }

            return false;
        }
    }
}

use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialTransferRepositoryInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferReason;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferResult;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryInitialTransferRepository;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialTransferAuthority;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$request = static fn (int $id = 71): DurableRetryInitialTransferRequest =>
    DurableRetryInitialTransferRequest::reconciliation(
        DurableRetryAuthorityIdentity::reconciliation($id),
        $id,
        new DateTimeImmutable('2032-02-03 04:05:06', new DateTimeZone('UTC'))
    );
$functional = static fn (
    string $status = 'pending',
    int $attempts = 0,
    ?string $owner = null,
    int $active = 0,
    int $version = 0
): array => [
    'reconciliation_status' => $status,
    'attempt_count' => (string) $attempts,
    'lease_owner' => $owner,
    'lease_acquired_at' => $owner === null ? null : '2032-02-03 04:00:00',
    'lease_expires_at' => $owner === null ? null : '2032-02-03 04:10:00',
    'lease_version' => (string) $version,
    'lease_active' => (string) $active,
];
$compatible = static fn (int $id = 71): array => [
    'id' => '44',
    'public_id' => hash('sha256', "existing-public-{$id}"),
    'stage' => 'reconciliation',
    'subject_id' => (string) $id,
    'completion_id' => (string) $id,
    'generation' => '1',
    'attempt_number' => '0',
    'scheduled_for' => '2032-02-03 04:05:06',
    'scheduled_action_id' => null,
    'dispatch_token_hash' => hash('sha256', "existing-token-{$id}"),
    'status' => 'dispatching',
    'active_slot' => '1',
    'version' => '1',
    'reason_code' => 'retryable_failure',
    'dispatched_at' => null,
    'claimed_at' => null,
    'consumed_at' => null,
    'terminal_at' => null,
    'created_at' => '2032-02-03 04:00:00',
    'updated_at' => '2032-02-03 04:00:00',
];
$run = static function (
    wpdb $db,
    int $id = 71
): DurableRetryInitialTransferResult {
    return (new DurableRetryInitialTransferRepository($db))
        ->transferReconciliation($GLOBALS['request']($id));
};
$GLOBALS['request'] = $request;

$db = new wpdb();
$db->functionalRows[] = $functional();
$result = $run($db);
$assert($result->state() === DurableRetryInitialTransferResult::TRANSFERRED, 'Creation must transfer.');
$queries = array_column($db->events, 1);
$assert($queries[0] === 'START TRANSACTION', 'Transaction must open first.');
$assert(str_contains($queries[2], 'payment_reconciliations') && str_contains($queries[2], 'FOR UPDATE'), 'Functional lock must be first read.');
$assert(str_contains($queries[4], 'durable_retry_schedules') && str_contains($queries[4], 'FOR UPDATE'), 'Durable lock must follow functional lock.');
$assert(count(array_filter(
    $db->events,
    fn (array $event): bool =>
        $event[0] === 'query' && str_starts_with($event[1], 'INSERT INTO')
)) === 1, 'Exactly one insert.');
$assert(end($queries) === 'COMMIT', 'Created transfer must commit.');

$db = new wpdb();
$db->functionalRows[] = $functional();
$db->durableRows[] = $compatible();
$result = $run($db);
$assert($result->state() === DurableRetryInitialTransferResult::ALREADY_TRANSFERRED, 'Compatible row must converge.');
$assert($result->reason() === DurableRetryInitialTransferReason::EQUIVALENT_TRANSFER_EXISTS, 'Compatible reason exact.');
$assert(count(array_filter(
    $db->events,
    fn (array $event): bool =>
        $event[0] === 'query' && str_starts_with($event[1], 'INSERT')
)) === 0, 'Compatible row writes zero.');

$db = new wpdb();
$db->functionalRows[] = $functional('processing', 1, 'worker_' . str_repeat('a', 32), 1, 1);
$result = $run($db);
$assert($result->state() === DurableRetryInitialTransferResult::LEGACY_IN_FLIGHT, 'Active lease must remain legacy.');
$assert(end($db->events)[1] === 'ROLLBACK', 'Active lease must rollback.');

$db = new wpdb();
$db->functionalRows[] = $functional('processing', 1, 'worker_' . str_repeat('c', 32), 0, 1);
$assert($run($db)->state() === DurableRetryInitialTransferResult::FUNCTIONALLY_INELIGIBLE, 'Expired legacy lease must not transfer.');

$db = new wpdb();
$db->functionalRows[] = $functional('pending', 0, 'worker_' . str_repeat('d', 32), 0, 1);
$assert($run($db)->state() === DurableRetryInitialTransferResult::FUNCTIONALLY_INELIGIBLE, 'Changed lease evidence must not transfer.');

$db = new wpdb();
$db->functionalRows[] = null;
$assert($run($db)->reason() === DurableRetryInitialTransferReason::FUNCTIONAL_RECORD_ABSENT, 'Absent functional record exact.');

$db = new wpdb();
$db->functionalRows[] = $functional('retryable', 1);
$assert($run($db)->reason() === DurableRetryInitialTransferReason::FUNCTIONAL_STATE_INELIGIBLE, 'Ineligible state exact.');

$db = new wpdb();
$db->functionalRows[] = $functional();
$bad = $compatible();
$bad['completion_id'] = '999';
$db->durableRows[] = $bad;
$result = $run($db);
$assert($result->state() === DurableRetryInitialTransferResult::DURABLE_INCONSISTENCY, 'Incompatible row must conflict.');
$assert($result->reason() === DurableRetryInitialTransferReason::EXISTING_TRANSFER_INCOMPATIBLE, 'Conflict reason exact.');

$db = new wpdb();
$db->functionalRows[] = $functional();
$db->durableRows[] = ['id' => 'broken'];
$assert($run($db)->state() === DurableRetryInitialTransferResult::DURABLE_INCONSISTENCY, 'Corrupt row must conflict.');

$db = new wpdb();
$db->functionalRows[] = $functional();
$db->durableRows[] = ['_batch' => [$compatible(), $compatible()]];
$result = $run($db);
$assert($result->state() === DurableRetryInitialTransferResult::DURABLE_INCONSISTENCY, 'Duplicate identity must conflict.');
$assert($result->reason() === DurableRetryInitialTransferReason::DUPLICATE_DURABLE_IDENTITY, 'Duplicate reason exact.');

$db = new wpdb();
$db->functionalRows[] = $functional();
$db->failInsert = true;
$result = $run($db);
$assert($result->state() === DurableRetryInitialTransferResult::PERSISTENCE_ERROR, 'Known write failure must be persistence error.');

$db = new wpdb();
$db->functionalRows[] = $functional();
$db->failCommit = true;
$result = $run($db);
$assert($result->state() === DurableRetryInitialTransferResult::TRANSFERRED, 'Uncertain commit resolved by exact row must transfer.');

$db = new wpdb();
$db->functionalRows[] = $functional();
$db->durableRows = [$compatible(), $compatible()];
$db->failCommit = true;
$result = $run($db);
$assert($result->state() === DurableRetryInitialTransferResult::ALREADY_TRANSFERRED, 'Uncertain commit with equivalent preexisting row must converge.');

$db = new wpdb();
$db->functionalRows[] = $functional();
$db->durableRows = [null, $compatible()];
$db->failInsert = true;
$result = $run($db);
$assert($result->state() === DurableRetryInitialTransferResult::ALREADY_TRANSFERRED, 'Lost insert race must converge.');

$db = new wpdb();
$db->functionalRows[] = $functional();
$db->failCommit = true;
$db->failDurableReadAt = 3;
$result = $run($db);
$assert($result->state() === DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN, 'Unresolved commit must remain uncertain.');

$db = new wpdb();
$db->failStart = true;
$assert($run($db)->state() === DurableRetryInitialTransferResult::PERSISTENCE_ERROR, 'Failed start is safe persistence error.');

$db = new wpdb();
$db->functionalRows[] = null;
$db->failRollback = true;
$assert($run($db)->state() === DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN, 'False rollback must be uncertain.');
$assert(count(array_filter($db->events, static fn (array $event): bool => $event[0] === 'query' && $event[1] === 'ROLLBACK')) === 1, 'False rollback is attempted exactly once.');

$db = new wpdb();
$db->functionalRows[] = $functional('processing', 1, 'worker_' . str_repeat('e', 32), 1, 1);
$db->throwRollback = true;
$assert($run($db)->state() === DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN, 'Exceptional rollback must be uncertain.');
$assert(count(array_filter($db->events, static fn (array $event): bool => $event[0] === 'query' && $event[1] === 'ROLLBACK')) === 1, 'Exceptional rollback is attempted exactly once.');

$db = new wpdb();
$db->functionalRows[] = $functional();
$db->throwInsert = true;
$assert($run($db)->state() === DurableRetryInitialTransferResult::PERSISTENCE_ERROR, 'Insert exception with confirmed rollback is a persistence error.');

$db = new wpdb();
$db->functionalRows[] = $functional();
$db->throwCommit = true;
$db->failExternalConnection = true;
$assert($run($db)->state() === DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN, 'Commit exception without independent evidence remains uncertain.');

$db = new wpdb();
$db->functionalRows[] = $functional();
$repositoryWithPhases = new DurableRetryInitialTransferRepository($db);
$phaseResult = $repositoryWithPhases->transferReconciliation($request());
$phaseProperty = new ReflectionProperty($repositoryWithPhases, 'phaseHistory');
$phaseHistory = $phaseProperty->getValue($repositoryWithPhases);
$assert($phaseResult->state() === DurableRetryInitialTransferResult::TRANSFERRED, 'Instrumented transfer succeeds.');
$assert($phaseHistory === [
    'PRE_TRANSACTION',
    'TRANSACTION_STARTED',
    'READS_AND_LOCKS',
    'PRE_WRITE',
    'WRITE_ATTEMPTED',
    'COMMIT_ATTEMPTED',
    'CLOSE_CONFIRMED',
], 'Creation exposes the exact phase sequence.');

$zeroCounters = [
    'repository_calls' => 1,
    'transaction_start' => 0,
    'functional_reads' => 0,
    'functional_locks' => 0,
    'durable_reads' => 0,
    'durable_locks' => 0,
    'insert_attempts' => 0,
    'commits' => 0,
    'rollbacks' => 0,
    'external_connections' => 0,
    'external_reads' => 0,
    'retries' => 0,
    'sleeps' => 0,
    'loops' => 0,
    'hooks' => 0,
    'callbacks' => 0,
    'scheduling' => 0,
    'processors' => 0,
];
$counts = static fn (array $overrides): array =>
    array_replace($GLOBALS['zeroCounters'], $overrides);
$GLOBALS['zeroCounters'] = $zeroCounters;
$row = static function (
    string $id,
    string $description,
    Closure $arrange,
    string $expectedResult,
    string $expectedMaxPhase,
    array $expectedCounters
): array {
    return [
        'id' => $id,
        'description' => $description,
        'arrange' => $arrange,
        'expected_result' => $expectedResult,
        'expected_max_phase' => $expectedMaxPhase,
        'expected_counters' => $expectedCounters,
        'allowed_operations' => array_keys(array_filter(
            $expectedCounters,
            static fn (int $value): bool => $value > 0
        )),
        'forbidden_operations' => array_keys(array_filter(
            $expectedCounters,
            static fn (int $value): bool => $value === 0
        )),
    ];
};
$ready = static function (wpdb $database) use ($functional): void {
    $database->functionalRows[] = $functional();
};
$absent = static function (wpdb $database): void {
    $database->functionalRows[] = null;
};
$busy = static function (wpdb $database) use ($functional): void {
    $database->functionalRows[] = $functional(
        'processing',
        1,
        'worker_' . str_repeat('f', 32),
        1,
        1
    );
};
$ineligible = static function (wpdb $database) use ($functional): void {
    $database->functionalRows[] = $functional('retryable', 1);
};
$preexisting = static function (wpdb $database) use ($ready, $compatible): void {
    $ready($database);
    $database->durableRows[] = $compatible();
};
$incompatible = static function (wpdb $database) use ($ready, $compatible): void {
    $ready($database);
    $row = $compatible();
    $row['completion_id'] = '999';
    $database->durableRows[] = $row;
};
$baseRead = ['transaction_start' => 1, 'functional_reads' => 1, 'functional_locks' => 1];
$closedFunctional = $counts($baseRead + ['rollbacks' => 1]);
$closedDurable = $counts($baseRead + [
    'durable_reads' => 1,
    'durable_locks' => 1,
    'rollbacks' => 1,
]);
$createdCounters = $counts($baseRead + [
    'durable_reads' => 2,
    'durable_locks' => 2,
    'insert_attempts' => 1,
    'commits' => 1,
]);
$uncertainCommitCounters = $counts($baseRead + [
    'durable_reads' => 3,
    'durable_locks' => 2,
    'insert_attempts' => 1,
    'commits' => 1,
    'external_connections' => 1,
    'external_reads' => 1,
]);
$scenarios = [
    $row('A4-01', 'request inválido', static function (wpdb $db): DurableRetryInitialTransferRequest {
        return (new ReflectionClass(DurableRetryInitialTransferRequest::class))->newInstanceWithoutConstructor();
    }, DurableRetryInitialTransferResult::PERSISTENCE_ERROR, 'PRE_TRANSACTION', $counts([])),
    $row('A4-02', 'registro ausente, rollback confirmado', $absent, DurableRetryInitialTransferResult::FUNCTIONALLY_INELIGIBLE, 'CLOSE_CONFIRMED', $closedFunctional),
    $row('A4-03', 'registro ausente, rollback falso', static function (wpdb $db) use ($absent): void {$absent($db); $db->failRollback = true;}, DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN, 'READS_AND_LOCKS', $closedFunctional),
    $row('A4-04', 'lease ocupado, rollback confirmado', $busy, DurableRetryInitialTransferResult::LEGACY_IN_FLIGHT, 'CLOSE_CONFIRMED', $closedFunctional),
    $row('A4-05', 'lease ocupado, rollback falso', static function (wpdb $db) use ($busy): void {$busy($db); $db->failRollback = true;}, DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN, 'READS_AND_LOCKS', $closedFunctional),
    $row('A4-06', 'estado inelegible', $ineligible, DurableRetryInitialTransferResult::FUNCTIONALLY_INELIGIBLE, 'CLOSE_CONFIRMED', $closedFunctional),
    $row('A4-07', 'compatible preexistente', $preexisting, DurableRetryInitialTransferResult::ALREADY_TRANSFERRED, 'CLOSE_CONFIRMED', $counts($baseRead + ['durable_reads' => 1, 'durable_locks' => 1, 'commits' => 1])),
    $row('A4-08', 'incompatible, rollback confirmado', $incompatible, DurableRetryInitialTransferResult::DURABLE_INCONSISTENCY, 'CLOSE_CONFIRMED', $closedDurable),
    $row('A4-09', 'incompatible, rollback falso', static function (wpdb $db) use ($incompatible): void {$incompatible($db); $db->failRollback = true;}, DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN, 'READS_AND_LOCKS', $closedDurable),
    $row('A4-10', 'durable corrupto', static function (wpdb $db) use ($ready): void {$ready($db); $db->durableRows[] = ['id' => 'broken'];}, DurableRetryInitialTransferResult::DURABLE_INCONSISTENCY, 'CLOSE_CONFIRMED', $closedDurable),
    $row('A4-11', 'durable duplicado', static function (wpdb $db) use ($ready, $compatible): void {$ready($db); $db->durableRows[] = ['_batch' => [$compatible(), $compatible()]];}, DurableRetryInitialTransferResult::DURABLE_INCONSISTENCY, 'CLOSE_CONFIRMED', $closedDurable),
    $row('A4-12', 'transferencia creada', $ready, DurableRetryInitialTransferResult::TRANSFERRED, 'CLOSE_CONFIRMED', $createdCounters),
    $row('A4-13', 'duplicate key compatible', static function (wpdb $db) use ($ready, $compatible): void {$ready($db); $db->durableRows = [null, $compatible()]; $db->failInsert = true;}, DurableRetryInitialTransferResult::ALREADY_TRANSFERRED, 'CLOSE_CONFIRMED', $createdCounters),
    $row('A4-14', 'duplicate key incompatible', static function (wpdb $db) use ($ready, $compatible): void {$ready($db); $bad = $compatible(); $bad['completion_id'] = '999'; $db->durableRows = [null, $bad]; $db->failInsert = true;}, DurableRetryInitialTransferResult::DURABLE_INCONSISTENCY, 'CLOSE_CONFIRMED', $counts($baseRead + ['durable_reads' => 2, 'durable_locks' => 2, 'insert_attempts' => 1, 'rollbacks' => 1])),
    $row('A4-15', 'duplicate key no concluyente', static function (wpdb $db) use ($ready): void {$ready($db); $db->failInsert = true; $db->failDurableReadAt = 2; $db->failRollback = true;}, DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN, 'WRITE_ATTEMPTED', $counts($baseRead + ['durable_reads' => 2, 'durable_locks' => 2, 'insert_attempts' => 1, 'rollbacks' => 1])),
    $row('A4-16', 'fallo lectura funcional, rollback confirmado', static function (wpdb $db): void {$db->failFunctionalRead = true;}, DurableRetryInitialTransferResult::PERSISTENCE_ERROR, 'CLOSE_CONFIRMED', $closedFunctional),
    $row('A4-17', 'fallo lectura funcional, rollback falso', static function (wpdb $db): void {$db->failFunctionalRead = true; $db->failRollback = true;}, DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN, 'READS_AND_LOCKS', $closedFunctional),
    $row('A4-18', 'fallo lectura durable', static function (wpdb $db) use ($ready): void {$ready($db); $db->failDurableReadAt = 1;}, DurableRetryInitialTransferResult::PERSISTENCE_ERROR, 'CLOSE_CONFIRMED', $closedDurable),
    $row('A4-19', 'fallo en PRE_WRITE', static function (wpdb $db) use ($ready): void {$ready($db); $db->throwInsertPrepare = true;}, DurableRetryInitialTransferResult::PERSISTENCE_ERROR, 'CLOSE_CONFIRMED', $counts($baseRead + ['durable_reads' => 1, 'durable_locks' => 1, 'rollbacks' => 1])),
    $row('A4-20', 'INSERT=false', static function (wpdb $db) use ($ready): void {$ready($db); $db->failInsert = true;}, DurableRetryInitialTransferResult::PERSISTENCE_ERROR, 'CLOSE_CONFIRMED', $counts($baseRead + ['durable_reads' => 2, 'durable_locks' => 2, 'insert_attempts' => 1, 'rollbacks' => 1])),
    $row('A4-21', 'excepción INSERT', static function (wpdb $db) use ($ready): void {$ready($db); $db->throwInsert = true;}, DurableRetryInitialTransferResult::PERSISTENCE_ERROR, 'CLOSE_CONFIRMED', $counts($baseRead + ['durable_reads' => 1, 'durable_locks' => 1, 'insert_attempts' => 1, 'rollbacks' => 1])),
    $row('A4-22', 'rollback falso', static function (wpdb $db) use ($ineligible): void {$ineligible($db); $db->failRollback = true;}, DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN, 'READS_AND_LOCKS', $closedFunctional),
    $row('A4-23', 'rollback excepcional', static function (wpdb $db) use ($ineligible): void {$ineligible($db); $db->throwRollback = true;}, DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN, 'READS_AND_LOCKS', $closedFunctional),
    $row('A4-24', 'commit confirmado', $ready, DurableRetryInitialTransferResult::TRANSFERRED, 'CLOSE_CONFIRMED', $createdCounters),
    $row('A4-25', 'commit falso, externa compatible', static function (wpdb $db) use ($ready): void {$ready($db); $db->failCommit = true;}, DurableRetryInitialTransferResult::TRANSFERRED, 'COMMIT_UNCERTAIN', $uncertainCommitCounters),
    $row('A4-26', 'commit falso, externa incompatible', static function (wpdb $db) use ($ready, $compatible): void {$ready($db); $bad = $compatible(); $bad['completion_id'] = '999'; $db->failCommit = true; $db->externalDurableRows = [$bad];}, DurableRetryInitialTransferResult::DURABLE_INCONSISTENCY, 'COMMIT_UNCERTAIN', $uncertainCommitCounters),
    $row('A4-27', 'commit falso, sin evidencia', static function (wpdb $db) use ($ready): void {$ready($db); $db->failCommit = true; $db->externalDurableRows = [];}, DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN, 'COMMIT_UNCERTAIN', $uncertainCommitCounters),
    $row('A4-28', 'excepción commit', static function (wpdb $db) use ($ready): void {$ready($db); $db->throwCommit = true;}, DurableRetryInitialTransferResult::TRANSFERRED, 'COMMIT_UNCERTAIN', $uncertainCommitCounters),
    $row('A4-29', 'fallo conexión externa', static function (wpdb $db) use ($ready): void {$ready($db); $db->failCommit = true; $db->failExternalConnection = true;}, DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN, 'COMMIT_UNCERTAIN', $counts($baseRead + ['durable_reads' => 2, 'durable_locks' => 2, 'insert_attempts' => 1, 'commits' => 1])),
    $row('A4-30', 'fallo lectura externa', static function (wpdb $db) use ($ready): void {$ready($db); $db->failCommit = true; $db->failDurableReadAt = 3;}, DurableRetryInitialTransferResult::OUTCOME_UNCERTAIN, 'COMMIT_UNCERTAIN', $uncertainCommitCounters),
];
$assert(count($scenarios) === 30, 'The normative matrix contains exactly 30 rows.');
$assert(count(array_unique(array_column($scenarios, 'id'))) === 30, 'The normative matrix has 30 unique identifiers.');
$requiredCounterNames = array_keys($zeroCounters);
$executedScenarios = 0;
foreach ($scenarios as $scenario) {
    foreach ([
        'id', 'description', 'arrange', 'expected_result',
        'expected_max_phase', 'expected_counters',
        'allowed_operations', 'forbidden_operations',
    ] as $requiredKey) {
        $assert(array_key_exists($requiredKey, $scenario), "{$scenario['id']} defines {$requiredKey}.");
    }
    $assert(array_keys($scenario['expected_counters']) === $requiredCounterNames, "{$scenario['id']} defines every counter.");
    $database = new wpdb();
    $arranged = ($scenario['arrange'])($database);
    $caseRequest = $arranged instanceof DurableRetryInitialTransferRequest
        ? $arranged
        : $request();
    $caseRepository = new DurableRetryInitialTransferRepository($database);
    $caseResult = $caseRepository->transferReconciliation($caseRequest);
    $casePhases = (new ReflectionProperty($caseRepository, 'phaseHistory'))
        ->getValue($caseRepository);
    $eventsByConnection = array_map(
        static fn (wpdb $connection): array => $connection->events,
        wpdb::instances()
    );
    $events = array_merge(...$eventsByConnection);
    $actualCounters = $zeroCounters;
    $actualCounters['transaction_start'] = count(array_filter($events, static fn (array $event): bool => $event[0] === 'query' && $event[1] === 'START TRANSACTION'));
    $actualCounters['functional_reads'] = count(array_filter($events, static fn (array $event): bool => $event[0] === 'read' && str_contains($event[1], 'payment_reconciliations')));
    $actualCounters['functional_locks'] = count(array_filter($events, static fn (array $event): bool => $event[0] === 'read' && str_contains($event[1], 'payment_reconciliations') && str_contains($event[1], 'FOR UPDATE')));
    $actualCounters['durable_reads'] = count(array_filter($events, static fn (array $event): bool => $event[0] === 'read' && str_contains($event[1], 'durable_retry_schedules')));
    $actualCounters['durable_locks'] = count(array_filter($events, static fn (array $event): bool => $event[0] === 'read' && str_contains($event[1], 'durable_retry_schedules') && str_contains($event[1], 'FOR UPDATE')));
    $actualCounters['insert_attempts'] = count(array_filter($events, static fn (array $event): bool => $event[0] === 'query' && str_starts_with($event[1], 'INSERT INTO')));
    $actualCounters['commits'] = count(array_filter($events, static fn (array $event): bool => $event[0] === 'query' && $event[1] === 'COMMIT'));
    $actualCounters['rollbacks'] = count(array_filter($events, static fn (array $event): bool => $event[0] === 'query' && $event[1] === 'ROLLBACK'));
    $actualCounters['external_connections'] = max(0, count($eventsByConnection) - 1);
    $actualCounters['external_reads'] = count(array_filter(array_slice($eventsByConnection, 1), static fn (array $connectionEvents): bool => count(array_filter($connectionEvents, static fn (array $event): bool => $event[0] === 'read')) > 0));
    $assert($caseResult->state() === $scenario['expected_result'], "{$scenario['id']} result: {$scenario['description']}.");
    $assert(end($casePhases) === $scenario['expected_max_phase'], "{$scenario['id']} maximum phase: {$scenario['description']} " . json_encode($casePhases, JSON_THROW_ON_ERROR));
    $assert($actualCounters === $scenario['expected_counters'], "{$scenario['id']} exact counters: " . json_encode([$actualCounters, $scenario['expected_counters']], JSON_THROW_ON_ERROR));
    foreach ($scenario['allowed_operations'] as $operation) {
        $assert($actualCounters[$operation] > 0, "{$scenario['id']} allows and observes {$operation}.");
    }
    foreach ($scenario['forbidden_operations'] as $operation) {
        $assert($actualCounters[$operation] === 0, "{$scenario['id']} forbids {$operation}.");
    }
    ++$executedScenarios;
}
$assert($executedScenarios === 30, 'Exactly 30 normative scenarios execute.');

$delegated = null;
$delegate = new class($delegated) implements DurableRetryInitialTransferRepositoryInterface {
    public function __construct(private mixed &$called)
    {
    }
    public function transferReconciliation(
        DurableRetryInitialTransferRequest $request
    ): DurableRetryInitialTransferResult {
        $this->called = $request;

        return DurableRetryInitialTransferResult::legacyInFlight();
    }
};
$input = $request(88);
$serviceResult = (new DurableRetryInitialTransferAuthority($delegate))
    ->transferReconciliation($input);
$assert($delegated === $input, 'Service must preserve request identity.');
$assert($serviceResult->state() === DurableRetryInitialTransferResult::LEGACY_IN_FLIGHT, 'Service delegates exact result.');

$source = file_get_contents(
    dirname(__DIR__, 2)
        . '/app/Modules/Orders/Repositories/DurableRetryInitialTransferRepository.php'
);
$assert(! str_contains($source, 'ActivationPolicy'), 'A4 must not consult A2.');
$assert(! str_contains($source, 'LegacyAuthority'), 'A4 must not consult A3.');
$assert(! preg_match('/\b(?:for|while)\s*\(/', $source), 'A4 must contain no loops.');
$assert(! str_contains($source, 'sleep('), 'A4 must contain no sleeps.');
$assert(! str_contains($source, 'as_schedule_'), 'A4 must contain no scheduling.');

echo "OK durable retry initial transfer authority ({$assertions} assertions)\n";
