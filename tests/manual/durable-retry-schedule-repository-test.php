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

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryScheduleRepository;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$rejects = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (InvalidArgumentException) {
        $assert(true, $message);
    }
};
$repository = static function (wpdb $db): DurableRetryScheduleRepository {
    return new DurableRetryScheduleRepository(
        $db,
        static fn (wpdb $database): bool => $database->duplicate
    );
};
$initial = static fn (
    string $public = '',
    int $subject = 10,
    int $generation = 1
): array => [
    'public_id' => $public !== '' ? $public : str_repeat('a', 64),
    'stage' => 'business_completion',
    'subject_id' => $subject,
    'completion_id' => null,
    'generation' => $generation,
    'attempt_number' => 0,
    'scheduled_for' => '2026-07-28 12:05:00',
    'scheduled_action_id' => null,
    'dispatch_token_hash' => str_repeat('b', 64),
    'status' => 'dispatching',
    'active_slot' => 1,
    'version' => 1,
    'reason_code' => 'retryable_failure',
    'dispatched_at' => null,
    'claimed_at' => null,
    'consumed_at' => null,
    'terminal_at' => null,
    'created_at' => '2026-07-28 12:00:00',
    'updated_at' => '2026-07-28 12:00:00',
];
$row = static function (array $fields, int $id = 7): array {
    $row = ['id' => (string) $id];
    foreach ($fields as $key => $value) {
        $row[$key] = is_int($value) ? (string) $value : $value;
    }

    return $row;
};
$snapshot = static function (array $databaseRow): DurableRetryScheduleSnapshot {
    foreach ([
        'id',
        'subject_id',
        'generation',
        'attempt_number',
        'version',
    ] as $field) {
        $databaseRow[$field] = (int) $databaseRow[$field];
    }
    foreach (['completion_id', 'scheduled_action_id', 'active_slot'] as $field) {
        $databaseRow[$field] = $databaseRow[$field] === null
            ? null
            : (int) $databaseRow[$field];
    }

    return DurableRetryScheduleSnapshot::fromArray($databaseRow);
};
$scheduledFields = static function (array $fields, int $actionId = 100): array {
    return array_replace($fields, [
        'scheduled_action_id' => $actionId,
        'status' => 'scheduled',
        'version' => $fields['version'] + 1,
        'dispatched_at' => '2026-07-28 12:01:00',
        'updated_at' => '2026-07-28 12:01:00',
    ]);
};

$db = new wpdb();
$db->queryQueue[] = ['result' => 1, 'insert_id' => 7];
$db->rowQueue[] = ['row' => $row($initial())];
$created = $repository($db)->create($initial());
$assert($created->code() === DurableRetryPersistenceResult::CREATED, 'first creation');
$assert($created->snapshot()?->id() === 7, 'created row is read durably');
$assert(
    array_keys($created->snapshot()->toArray()) === array_merge(['id'], array_keys($initial())),
    'exact twenty-column hydration'
);
$assert(
    str_starts_with($db->queries[0], 'INSERT INTO wp_va_durable_retry_schedules'),
    'physical table derived from prefix'
);
$assert(
    count($db->prepared[0][1]) === 13
        && str_contains($db->prepared[0][0], 'NULL'),
    'nullable values represented explicitly without unprepared values'
);

$db = new wpdb();
$db->queryQueue[] = ['result' => false, 'duplicate' => true, 'error' => 'duplicate'];
$db->rowQueue[] = ['row' => $row($initial())];
$existing = $repository($db)->create($initial());
$assert(
    $existing->code() === DurableRetryPersistenceResult::EXISTING_COMPATIBLE,
    'compatible repeated creation'
);

$db = new wpdb();
$db->queryQueue[] = ['result' => false, 'duplicate' => true];
$raced = $initial();
$db->rowQueue[] = ['row' => $row($raced)];
$assert(
    $repository($db)->create($initial())->code()
        === DurableRetryPersistenceResult::EXISTING_COMPATIBLE,
    'insert race loser rereads authoritative row'
);

$db = new wpdb();
$db->queryQueue[] = ['result' => false, 'duplicate' => true];
$incompatible = $initial();
$incompatible['scheduled_for'] = '2026-07-28 12:06:00';
$db->rowQueue[] = ['row' => $row($incompatible)];
$assert(
    $repository($db)->create($initial())->code()
        === DurableRetryPersistenceResult::CONFLICT,
    'incompatible duplicate collision'
);

foreach ([
    'public ID unique collision without canonical identity',
    'active slot collision without canonical identity',
] as $collision) {
    $db = new wpdb();
    $db->queryQueue[] = ['result' => false, 'duplicate' => true];
    $db->rowQueue[] = ['row' => null];
    $assert(
        $repository($db)->create($initial())->code()
            === DurableRetryPersistenceResult::CONFLICT,
        $collision
    );
}

$db = new wpdb();
$db->queryQueue[] = ['result' => false, 'error' => 'arbitrary'];
$assert(
    $repository($db)->create($initial())->code()
        === DurableRetryPersistenceResult::PERSISTENCE_ERROR,
    'arbitrary SQL error is not idempotence'
);

$db = new wpdb();
$db->rowQueue[] = ['row' => $row($initial())];
$found = $repository($db)->findById(7);
$assert($found->snapshot()?->toArray()['completion_id'] === null, 'nullable hydration');
$assert(
    $found->snapshot()?->toArray()['scheduled_action_id'] === null,
    'nullable external action hydration'
);
$assert(str_contains($db->prepared[0][0], 'WHERE id = %d'), 'prepared ID lookup');

$db = new wpdb();
$withAction = $scheduledFields($initial());
$db->rowQueue[] = ['row' => $row($withAction)];
$identityRead = $repository($db)->findByIdentity('business_completion', 10, 1);
$assert(
    $identityRead->snapshot()?->toArray()['scheduled_action_id'] === 100,
    'canonical identity read hydrates external action'
);
$assert(count($db->prepared[0][1]) === 3, 'all identity values prepared');

$db = new wpdb();
$db->rowQueue[] = ['row' => null];
$assert(
    $repository($db)->findById(999)->code()
        === DurableRetryPersistenceResult::NOT_FOUND,
    'missing row'
);
$db = new wpdb();
$invalidRow = $row($initial());
$invalidRow['status'] = 'invalid';
$db->rowQueue[] = ['row' => $invalidRow];
$assert(
    $repository($db)->findById(7)->code()
        === DurableRetryPersistenceResult::PERSISTENCE_ERROR,
    'invalid persisted snapshot closes safely'
);

$invalidRows = [];
$missingColumn = $row($initial());
unset($missingColumn['updated_at']);
$invalidRows['missing column'] = $missingColumn;
$extraColumn = $row($initial());
$extraColumn['unexpected'] = 'value';
$invalidRows['extra column'] = $extraColumn;
$emptyNullable = $row($initial());
$emptyNullable['completion_id'] = '';
$invalidRows['empty nullable integer'] = $emptyNullable;
$zeroPositive = $row($initial());
$zeroPositive['subject_id'] = '0';
$invalidRows['zero positive integer'] = $zeroPositive;
$negative = $row($initial());
$negative['generation'] = '-1';
$invalidRows['negative integer'] = $negative;
$decimal = $row($initial());
$decimal['version'] = '1.5';
$invalidRows['truncated decimal'] = $decimal;
$badTimestamp = $row($initial());
$badTimestamp['scheduled_for'] = '2030-02-30 00:00:00';
$invalidRows['invalid timestamp'] = $badTimestamp;
$unknownStage = $row($initial());
$unknownStage['stage'] = 'unknown';
$invalidRows['unknown stage'] = $unknownStage;
$unknownReason = $row($initial());
$unknownReason['reason_code'] = 'unknown';
$invalidRows['unknown reason'] = $unknownReason;
foreach ($invalidRows as $case => $invalidPersistedRow) {
    $db = new wpdb();
    $db->rowQueue[] = ['row' => $invalidPersistedRow];
    $assert(
        $repository($db)->findById(7)->code()
            === DurableRetryPersistenceResult::PERSISTENCE_ERROR,
        "strict hydration rejects {$case}"
    );
}

$beforeRow = $row($initial());
$before = $snapshot($beforeRow);
$afterRow = $row($scheduledFields($initial()));
$after = $snapshot($afterRow);
$db = new wpdb();
$db->queryQueue[] = ['result' => 1];
$db->rowQueue[] = ['row' => $afterRow];
$applied = $repository($db)->transition($before, $after);
$assert($applied->code() === DurableRetryPersistenceResult::APPLIED, 'valid CAS');
$assert($applied->snapshot()?->version() === 2, 'CAS increments durable version');
$assert(
    str_contains($db->prepared[0][0], 'WHERE id = %d AND public_id = %s')
        && str_contains($db->prepared[0][0], 'AND status = %s AND version = %d')
        && str_contains($db->prepared[0][0], 'scheduled_action_id IS NULL'),
    'CAS WHERE carries durable identity, expected status and version'
);

$db = new wpdb();
$db->queryQueue[] = ['result' => 0];
$db->rowQueue[] = ['row' => $afterRow];
$assert(
    $repository($db)->transition($before, $after)->code()
        === DurableRetryPersistenceResult::ALREADY_APPLIED,
    'zero-row CAS classifies same result as idempotent'
);

$claimed = $scheduledFields($initial());
$claimed['status'] = 'claimed';
$claimed['active_slot'] = 1;
$claimed['version'] = 3;
$claimed['claimed_at'] = '2026-07-28 12:02:00';
$claimed['updated_at'] = '2026-07-28 12:02:00';
$db = new wpdb();
$db->queryQueue[] = ['result' => 0];
$db->rowQueue[] = ['row' => $row($claimed)];
$assert(
    $repository($db)->transition($before, $after)->code()
        === DurableRetryPersistenceResult::UNEXPECTED_STATE,
    'zero-row CAS classifies unexpected state'
);

$newer = $initial();
$newer['version'] = 3;
$db = new wpdb();
$db->queryQueue[] = ['result' => 0];
$db->rowQueue[] = ['row' => $row($newer)];
$assert(
    $repository($db)->transition($before, $after)->code()
        === DurableRetryPersistenceResult::AUTHORITY_LOST,
    'stale version loses authority and prevents ABA'
);

$conflicting = $initial();
$conflicting['completion_id'] = 50;
$db = new wpdb();
$db->queryQueue[] = ['result' => 0];
$db->rowQueue[] = ['row' => $row($conflicting)];
$assert(
    $repository($db)->transition($before, $after)->code()
        === DurableRetryPersistenceResult::CONFLICT,
    'same authority with different durable values is conflict'
);

$db = new wpdb();
$db->queryQueue[] = ['result' => 0];
$db->rowQueue[] = ['row' => null];
$assert(
    $repository($db)->transition($before, $after)->code()
        === DurableRetryPersistenceResult::NOT_FOUND,
    'zero-row CAS classifies missing row'
);

$terminal = $scheduledFields($initial());
$terminal['status'] = 'cancelled';
$terminal['active_slot'] = null;
$terminal['reason_code'] = 'cancelled_by_authority';
$terminal['version'] = 3;
$terminal['terminal_at'] = '2026-07-28 12:02:00';
$terminal['updated_at'] = '2026-07-28 12:02:00';
$terminalSnapshot = $snapshot($row($terminal));
$revived = $terminal;
$revived['status'] = 'scheduled';
$revived['active_slot'] = 1;
$revived['reason_code'] = 'retryable_failure';
$revived['version'] = 4;
$revived['terminal_at'] = null;
$revived['updated_at'] = '2026-07-28 12:03:00';
$rejects(
    static fn () => $repository(new wpdb())->transition(
        $terminalSnapshot,
        $snapshot($row($revived))
    ),
    'terminal state cannot be revived'
);

$claimedReplacement = $claimed;
$claimedReplacement['scheduled_action_id'] = 101;
$claimedReplacement['version'] = 3;
$rejects(
    static fn () => $repository(new wpdb())->transition(
        $snapshot($afterRow),
        $snapshot($row($claimedReplacement))
    ),
    'associated action ID is write-once'
);

$db = new wpdb();
$db->rowQueue[] = ['row' => $beforeRow];
$db->queryQueue[] = ['result' => 1];
$db->rowQueue[] = ['row' => $afterRow];
$attached = $repository($db)->associateScheduledAction(
    7,
    1,
    100,
    '2026-07-28 12:01:00',
    '2026-07-28 12:01:00'
);
$assert($attached->code() === DurableRetryPersistenceResult::APPLIED, 'initial action association');

$db = new wpdb();
$db->rowQueue[] = ['row' => $afterRow];
$assert(
    $repository($db)->associateScheduledAction(
        7,
        1,
        100,
        '2026-07-28 12:01:00',
        '2026-07-28 12:01:00'
    )->code() === DurableRetryPersistenceResult::ALREADY_APPLIED,
    'same action association is idempotent'
);
$db = new wpdb();
$timestampConflict = $afterRow;
$timestampConflict['dispatched_at'] = '2026-07-28 12:01:01';
$timestampConflict['updated_at'] = '2026-07-28 12:01:01';
$db->rowQueue[] = ['row' => $timestampConflict];
$assert(
    $repository($db)->associateScheduledAction(
        7,
        1,
        100,
        '2026-07-28 12:01:00',
        '2026-07-28 12:01:00'
    )->code() === DurableRetryPersistenceResult::CONFLICT,
    'same action with different timestamps is not already applied'
);
$db = new wpdb();
$db->rowQueue[] = ['row' => $row($claimed)];
$assert(
    $repository($db)->associateScheduledAction(
        7,
        2,
        100,
        '2026-07-28 12:01:00',
        '2026-07-28 12:01:00'
    )->code() === DurableRetryPersistenceResult::UNEXPECTED_STATE,
    'same action after a later transition is not immediate idempotence'
);
$db = new wpdb();
$staleDispatching = $beforeRow;
$staleDispatching['version'] = '2';
$db->rowQueue[] = ['row' => $staleDispatching];
$assert(
    $repository($db)->associateScheduledAction(
        7,
        1,
        100,
        '2026-07-28 12:01:00',
        '2026-07-28 12:01:00'
    )->code() === DurableRetryPersistenceResult::AUTHORITY_LOST,
    'stale association version loses authority'
);
$db = new wpdb();
$db->rowQueue[] = ['row' => null];
$assert(
    $repository($db)->associateScheduledAction(
        999,
        1,
        100,
        '2026-07-28 12:01:00',
        '2026-07-28 12:01:00'
    )->code() === DurableRetryPersistenceResult::NOT_FOUND,
    'association classifies missing row'
);
$db = new wpdb();
$db->rowQueue[] = ['row' => $afterRow];
$assert(
    $repository($db)->associateScheduledAction(
        7,
        1,
        101,
        '2026-07-28 12:01:00',
        '2026-07-28 12:01:00'
    )->code() === DurableRetryPersistenceResult::CONFLICT,
    'different associated action conflicts'
);

$db = new wpdb();
$db->rowQueue[] = ['row' => $beforeRow];
$db->queryQueue[] = ['result' => false, 'duplicate' => true];
$assert(
    $repository($db)->associateScheduledAction(
        7,
        1,
        100,
        '2026-07-28 12:01:00',
        '2026-07-28 12:01:00'
    )->code() === DurableRetryPersistenceResult::CONFLICT,
    'external action unique collision conflicts'
);

$db = new wpdb();
$firstInitial = $initial(str_repeat('c', 64), 20, 1);
$secondInitial = $initial(str_repeat('d', 64), 21, 1);
$db->queryQueue = [
    ['result' => 1, 'insert_id' => 20],
    ['result' => 1, 'insert_id' => 21],
];
$db->rowQueue = [
    ['row' => $row($firstInitial, 20)],
    ['row' => $row($secondInitial, 21)],
];
$repo = $repository($db);
$assert(
    $repo->create($firstInitial)->code() === DurableRetryPersistenceResult::CREATED
        && $repo->create($secondInitial)->code() === DurableRetryPersistenceResult::CREATED,
    'multiple schedules retain NULL external action'
);

$db = new wpdb();
$db->queryQueue[] = ['result' => 1];
$db->rowQueue[] = ['row' => $afterRow];
$winner = $repository($db)->transition($before, $after);
$db->queryQueue[] = ['result' => 0];
$db->rowQueue[] = ['row' => $afterRow];
$loser = $repository($db)->transition($before, $after);
$assert(
    $winner->code() === DurableRetryPersistenceResult::APPLIED
        && $loser->code() === DurableRetryPersistenceResult::ALREADY_APPLIED,
    'two concurrent equivalent actors yield one authority winner'
);

foreach ($db->prepared as [$template]) {
    $assert(! preg_match('/(?:public_id|stage|subject_id|generation|status|version) = [^%N]/', $template), 'dynamic CAS values remain placeholders');
}

echo "durable retry schedule repository: {$assertions} assertions\n";
