<?php

declare(strict_types=1);

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (! class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public int $insert_id = 0;
        public mixed $dbh = null;
        public bool $duplicate = false;
        public array $queryQueue = [];
        public array $rowQueue = [];
        public array $prepared = [];
        public array $queries = [];

        public function prepare(string $sql, mixed ...$values): string
        {
            $this->prepared[] = [$sql, $values];
            $position = 0;

            return preg_replace_callback(
                '/%[ds]/',
                static function (array $match) use ($values, &$position): string {
                    $value = $values[$position++];

                    return $match[0] === '%d'
                        ? (string) $value
                        : "'" . str_replace("'", "''", (string) $value) . "'";
                },
                $sql
            );
        }

        public function query(string $sql): int|false
        {
            $this->queries[] = $sql;
            $next = array_shift($this->queryQueue) ?? ['result' => false];
            $this->last_error = $next['error'] ?? '';
            $this->insert_id = $next['insert_id'] ?? 0;
            $this->duplicate = $next['duplicate'] ?? false;

            return $next['result'];
        }

        public function get_row(string $sql, string $format): ?array
        {
            $this->queries[] = $sql;
            $next = array_shift($this->rowQueue) ?? ['row' => null];
            $this->last_error = $next['error'] ?? '';

            return $next['row'];
        }
    }
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextAttemptDecision;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextGenerationPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryScheduleRepository;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$row = static function (array $fields): array {
    $result = [];
    foreach ($fields as $name => $value) {
        $result[$name] = is_int($value) ? (string) $value : $value;
    }

    return $result;
};
$claimedFields = static fn (): array => [
    'id' => 41,
    'public_id' => str_repeat('a', 64),
    'stage' => 'business_completion',
    'subject_id' => 810,
    'completion_id' => 710,
    'generation' => 2,
    'attempt_number' => 2,
    'scheduled_for' => '2030-01-01 00:01:00',
    'scheduled_action_id' => 910,
    'dispatch_token_hash' => str_repeat('b', 64),
    'status' => 'claimed',
    'active_slot' => 1,
    'version' => 4,
    'reason_code' => 'retryable_failure',
    'dispatched_at' => '2030-01-01 00:00:30',
    'claimed_at' => '2030-01-01 00:01:00',
    'consumed_at' => null,
    'terminal_at' => null,
    'created_at' => '2030-01-01 00:00:00',
    'updated_at' => '2030-01-01 00:01:00',
];
$supersededFields = static fn (): array => array_replace($claimedFields(), [
    'status' => 'superseded',
    'active_slot' => null,
    'version' => 5,
    'reason_code' => 'superseded_generation',
    'terminal_at' => '2030-01-01 00:02:00',
    'updated_at' => '2030-01-01 00:02:00',
]);
$successorFields = static fn (): array => [
    'id' => 42,
    'public_id' => str_repeat('c', 64),
    'stage' => 'business_completion',
    'subject_id' => 810,
    'completion_id' => 710,
    'generation' => 3,
    'attempt_number' => 3,
    'scheduled_for' => '2030-01-01 00:04:00',
    'scheduled_action_id' => null,
    'dispatch_token_hash' => str_repeat('d', 64),
    'status' => 'dispatching',
    'active_slot' => 1,
    'version' => 1,
    'reason_code' => 'retryable_failure',
    'dispatched_at' => null,
    'claimed_at' => null,
    'consumed_at' => null,
    'terminal_at' => null,
    'created_at' => '2030-01-01 00:02:00',
    'updated_at' => '2030-01-01 00:02:00',
];
$repository = static function (wpdb $db): DurableRetryScheduleRepository {
    return new DurableRetryScheduleRepository(
        $db,
        static fn (wpdb $database): bool => $database->duplicate
    );
};
$decision = DurableRetryNextAttemptDecision::retry(
    3,
    3,
    '2030-01-01 00:04:00',
    120
);
$claimed = DurableRetryScheduleSnapshot::fromArray($claimedFields());

$db = new wpdb();
$db->queryQueue = [
    ['result' => 1],
    ['result' => 1],
    ['result' => 1, 'insert_id' => 42],
    ['result' => 1],
];
$db->rowQueue = [
    ['row' => $row($supersededFields())],
    ['row' => $row($successorFields())],
];
$created = $repository($db)->supersedeAndCreateNextGeneration(
    $claimed,
    $decision,
    '2030-01-01 00:02:00'
);
$assert($created->code() === DurableRetryNextGenerationPersistenceResult::CREATED, 'created');
$assert($created->succeeded() && ! $created->converged(), 'created flags');
$assert($created->insertedByThisCall(), 'created by caller');
$historical = $created->superseded()?->toArray();
$successor = $created->successor()?->toArray();
$assert($historical['id'] === 41 && $successor['id'] === 42, 'new durable identity');
$assert($historical['generation'] === 2 && $successor['generation'] === 3, 'generation N+1');
$assert($successor['attempt_number'] === 3, 'attempt persisted exactly once');
$assert($successor['scheduled_for'] === '2030-01-01 00:04:00', 'schedule exact');
$assert($successor['stage'] === $historical['stage'], 'stage preserved');
$assert($successor['subject_id'] === $historical['subject_id'], 'subject preserved');
$assert($successor['completion_id'] === $historical['completion_id'], 'logical relation preserved');
$assert($historical['status'] === 'superseded', 'historical superseded');
$assert($successor['status'] === 'dispatching', 'successor dispatching');
$assert($historical['active_slot'] === null && $successor['active_slot'] === 1, 'slot transferred');
$assert($historical['scheduled_action_id'] === 910, 'historical action preserved');
$assert($successor['scheduled_action_id'] === null, 'successor action absent');
$assert($historical['claimed_at'] === '2030-01-01 00:01:00', 'historical claim preserved');
$assert($successor['claimed_at'] === null, 'successor claim absent');
$assert($historical['terminal_at'] === '2030-01-01 00:02:00', 'historical terminal exact');
$assert($successor['terminal_at'] === null, 'successor terminal absent');
$assert($successor['created_at'] === '2030-01-01 00:02:00', 'successor created explicit');
$assert($successor['updated_at'] === '2030-01-01 00:02:00', 'successor updated explicit');
$assert($historical['version'] === 5 && $successor['version'] === 1, 'versions exact');
$assert($db->queries[0] === 'START TRANSACTION', 'transaction starts first');
$assert(str_starts_with($db->queries[1], 'UPDATE '), 'CAS before insert');
$assert(str_starts_with($db->queries[2], 'INSERT INTO '), 'new row inserted');
$assert($db->queries[array_key_last($db->queries)] === 'COMMIT', 'commit after evidence');
$cas = $db->prepared[0][0];
foreach ([
    'id = %d',
    'public_id = %s',
    'stage = %s',
    'subject_id = %d',
    'generation = %d',
    'attempt_number = %d',
    'status = %s',
    'active_slot = %d',
    'version = %d',
    'scheduled_action_id = %d',
    'dispatch_token_hash = %s',
] as $predicate) {
    $assert(str_contains($cas, $predicate), "CAS predicate {$predicate}");
}

$invalid = [
    'wrong generation' => DurableRetryNextAttemptDecision::retry(
        4,
        3,
        '2030-01-01 00:04:00',
        120
    ),
    'wrong attempt' => DurableRetryNextAttemptDecision::retry(
        3,
        4,
        '2030-01-01 00:04:00',
        120
    ),
    'wrong backoff relation' => DurableRetryNextAttemptDecision::retry(
        3,
        3,
        '2030-01-01 00:10:00',
        120
    ),
    'not retry' => DurableRetryNextAttemptDecision::terminal(),
];
foreach ($invalid as $case => $invalidDecision) {
    $db = new wpdb();
    $result = $repository($db)->supersedeAndCreateNextGeneration(
        $claimed,
        $invalidDecision,
        '2030-01-01 00:02:00'
    );
    $assert(
        $result->code() === DurableRetryNextGenerationPersistenceResult::INVALID_DECISION,
        $case
    );
    $assert($db->queries === [], "{$case} performs zero queries");
}
foreach ([
    '2030-01-01T00:02:00Z',
    ' 2030-01-01 00:02:00',
    '2030-01-01 00:02:00 ',
    '2030-02-30 00:02:00',
    '0',
] as $invalidTimestamp) {
    $db = new wpdb();
    $result = $repository($db)->supersedeAndCreateNextGeneration(
        $claimed,
        $decision,
        $invalidTimestamp
    );
    $assert(
        $result->code() === DurableRetryNextGenerationPersistenceResult::INVALID_DECISION,
        "invalid timestamp {$invalidTimestamp}"
    );
    $assert($db->queries === [], 'invalid timestamp opens no transaction');
}

$notClaimedFields = array_replace($claimedFields(), [
    'status' => 'scheduled',
    'claimed_at' => null,
]);
$db = new wpdb();
$ineligible = $repository($db)->supersedeAndCreateNextGeneration(
    DurableRetryScheduleSnapshot::fromArray($notClaimedFields),
    $decision,
    '2030-01-01 00:02:00'
);
$assert($ineligible->code() === DurableRetryNextGenerationPersistenceResult::INELIGIBLE_STATE, 'claimed required');
$assert($db->queries === [], 'ineligible snapshot opens no transaction');

$db = new wpdb();
$db->queryQueue = [
    ['result' => 1],
    ['result' => 0],
    ['result' => 1],
];
$db->rowQueue = [
    ['row' => $row($supersededFields())],
    ['row' => $row($successorFields())],
];
$converged = $repository($db)->supersedeAndCreateNextGeneration(
    $claimed,
    $decision,
    '2030-01-01 00:02:00'
);
$assert(
    $converged->code() === DurableRetryNextGenerationPersistenceResult::CONCURRENT_CONVERGENCE,
    'concurrent convergence'
);
$assert($converged->succeeded() && $converged->converged(), 'convergence flags');
$assert(! $converged->insertedByThisCall(), 'loser did not insert');
$assert(count(array_filter($db->queries, static fn (string $sql): bool => str_starts_with($sql, 'INSERT'))) === 0, 'CAS loser never inserts');

$db = new wpdb();
$db->queryQueue = [
    ['result' => 1],
    ['result' => 1],
    ['result' => false, 'duplicate' => true],
    ['result' => 1],
];
$collision = $repository($db)->supersedeAndCreateNextGeneration(
    $claimed,
    $decision,
    '2030-01-01 00:02:00'
);
$assert($collision->code() === DurableRetryNextGenerationPersistenceResult::ACTIVE_SLOT_CONFLICT, 'slot collision');
$assert(in_array('ROLLBACK', $db->queries, true), 'slot collision rolls back');

$db = new wpdb();
$db->queryQueue = [
    ['result' => 1],
    ['result' => 1],
    ['result' => false],
    ['result' => 1],
];
$failedInsert = $repository($db)->supersedeAndCreateNextGeneration(
    $claimed,
    $decision,
    '2030-01-01 00:02:00'
);
$assert($failedInsert->code() === DurableRetryNextGenerationPersistenceResult::INSERT_FAILED, 'insert failure');
$assert($db->queries[array_key_last($db->queries)] === 'ROLLBACK', 'insert failure rolls back');

$db = new wpdb();
$db->queryQueue = [
    ['result' => false, 'error' => 'engine detail'],
];
$beginFailure = $repository($db)->supersedeAndCreateNextGeneration(
    $claimed,
    $decision,
    '2030-01-01 00:02:00'
);
$assert($beginFailure->code() === DurableRetryNextGenerationPersistenceResult::PERSISTENCE_ERROR, 'begin failure');
$assert($db->queries === ['START TRANSACTION'], 'begin failure writes nothing');

$db = new wpdb();
$db->queryQueue = [
    ['result' => 1],
    ['result' => 1],
    ['result' => false, 'error' => 'engine detail'],
    ['result' => false, 'error' => 'rollback detail'],
];
$rollbackFailure = $repository($db)->supersedeAndCreateNextGeneration(
    $claimed,
    $decision,
    '2030-01-01 00:02:00'
);
$assert($rollbackFailure->code() === DurableRetryNextGenerationPersistenceResult::PERSISTENCE_ERROR, 'rollback failure is not atomic success');

$db = new wpdb();
$db->queryQueue = [
    ['result' => 1],
    ['result' => 1],
    ['result' => 1, 'insert_id' => 42],
    ['result' => 1],
];
$db->rowQueue = [
    ['row' => null],
    ['row' => $row($successorFields())],
];
$evidenceFailure = $repository($db)->supersedeAndCreateNextGeneration(
    $claimed,
    $decision,
    '2030-01-01 00:02:00'
);
$assert($evidenceFailure->code() === DurableRetryNextGenerationPersistenceResult::DURABLE_INCONSISTENCY, 'missing evidence');
$assert($db->queries[array_key_last($db->queries)] === 'ROLLBACK', 'evidence failure rolls back');

$generationOverflowFields = array_replace($claimedFields(), [
    'generation' => 4_294_967_295,
]);
$generationOverflow = DurableRetryScheduleSnapshot::fromArray($generationOverflowFields);
$overflowDecision = DurableRetryNextAttemptDecision::retry(
    4_294_967_296,
    3,
    '2030-01-01 00:04:00',
    120
);
$db = new wpdb();
$overflow = $repository($db)->supersedeAndCreateNextGeneration(
    $generationOverflow,
    $overflowDecision,
    '2030-01-01 00:02:00'
);
$assert($overflow->code() === DurableRetryNextGenerationPersistenceResult::INVALID_DECISION, 'durable generation overflow');
$assert($db->queries === [], 'overflow opens no transaction');

$versionOverflowFields = array_replace($claimedFields(), [
    'version' => 4_294_967_295,
]);
$db = new wpdb();
$versionOverflow = $repository($db)->supersedeAndCreateNextGeneration(
    DurableRetryScheduleSnapshot::fromArray($versionOverflowFields),
    $decision,
    '2030-01-01 00:02:00'
);
$assert($versionOverflow->code() === DurableRetryNextGenerationPersistenceResult::INELIGIBLE_STATE, 'durable version overflow');
$assert($db->queries === [], 'version overflow opens no transaction');

foreach ([
    'stale version' => array_replace($claimedFields(), ['version' => 3]),
    'stale generation' => array_replace($claimedFields(), ['generation' => 1]),
    'external action mismatch' => array_replace($claimedFields(), ['scheduled_action_id' => 911]),
] as $case => $staleFields) {
    $staleDecision = DurableRetryNextAttemptDecision::retry(
        $staleFields['generation'] + 1,
        $staleFields['attempt_number'] + 1,
        '2030-01-01 00:04:00',
        120
    );
    $db = new wpdb();
    $db->queryQueue = [
        ['result' => 1],
        ['result' => 0],
        ['result' => 1],
    ];
    $db->rowQueue = [
        ['row' => $row($claimedFields())],
        ['row' => null],
    ];
    $lost = $repository($db)->supersedeAndCreateNextGeneration(
        DurableRetryScheduleSnapshot::fromArray($staleFields),
        $staleDecision,
        '2030-01-01 00:02:00'
    );
    $assert($lost->code() === DurableRetryNextGenerationPersistenceResult::CAS_CONFLICT, $case);
    $assert(
        count(array_filter($db->queries, static fn (string $sql): bool => str_starts_with($sql, 'INSERT'))) === 0,
        "{$case} never inserts"
    );
}

$resultReflection = new ReflectionClass(DurableRetryNextGenerationPersistenceResult::class);
$assert($resultReflection->isFinal(), 'result final');
foreach ($resultReflection->getProperties() as $property) {
    $assert($property->isReadOnly(), "result property {$property->getName()} readonly");
}
$assert(! method_exists($created, 'sql'), 'result exposes no SQL');
$assert(! method_exists($created, 'exception'), 'result exposes no exception');

echo "durable retry next generation repository: {$assertions} assertions\n";
