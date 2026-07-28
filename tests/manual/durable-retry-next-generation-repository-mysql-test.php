<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextAttemptDecision;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextGenerationPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryScheduleRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

global $wpdb;

$table = $wpdb->prefix . 'va_durable_retry_schedules';
$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
if ($found !== $table) {
    echo "durable retry next generation repository mysql: SKIP (table {$table} is absent)\n";
    exit(0);
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$seed = bin2hex(random_bytes(16));
$subjectA = random_int(1_000_000_000, 1_300_000_000);
$subjectB = $subjectA + 1;
$actionA = random_int(1_300_000_001, 1_600_000_000);
$actionB = $actionA + 1;
$repository = new DurableRetryScheduleRepository($wpdb);
$subjects = [$subjectA, $subjectB];
$initial = static fn (
    int $subject,
    string $suffix
): array => [
    'public_id' => hash('sha256', "next-generation-public|{$suffix}"),
    'stage' => 'business_completion',
    'subject_id' => $subject,
    'completion_id' => $subject + 100,
    'generation' => 1,
    'attempt_number' => 1,
    'scheduled_for' => '2030-01-01 00:01:00',
    'scheduled_action_id' => null,
    'dispatch_token_hash' => hash('sha256', "next-generation-token|{$suffix}"),
    'status' => 'dispatching',
    'active_slot' => 1,
    'version' => 1,
    'reason_code' => 'retryable_failure',
    'dispatched_at' => null,
    'claimed_at' => null,
    'consumed_at' => null,
    'terminal_at' => null,
    'created_at' => '2030-01-01 00:00:00',
    'updated_at' => '2030-01-01 00:00:00',
];
$claim = static function (
    DurableRetryScheduleRepository $repository,
    array $fields,
    int $actionId
): DurableRetryScheduleSnapshot {
    $created = $repository->create($fields)->snapshot();
    if ($created === null) {
        throw new RuntimeException('Failed to create MySQL fixture.');
    }
    $scheduled = $repository->associateScheduledAction(
        $created->id(),
        1,
        $actionId,
        '2030-01-01 00:00:30',
        '2030-01-01 00:00:30'
    )->snapshot();
    if ($scheduled === null) {
        throw new RuntimeException('Failed to schedule MySQL fixture.');
    }
    $claimed = DurableRetryScheduleSnapshot::fromArray(array_replace(
        $scheduled->toArray(),
        [
            'status' => 'claimed',
            'version' => 3,
            'claimed_at' => '2030-01-01 00:01:00',
            'updated_at' => '2030-01-01 00:01:00',
        ]
    ));
    $persisted = $repository->transition($scheduled, $claimed)->snapshot();
    if ($persisted === null) {
        throw new RuntimeException('Failed to claim MySQL fixture.');
    }

    return $persisted;
};
$previousSuppressErrors = $wpdb->suppress_errors(true);

try {
    $claimedA = $claim($repository, $initial($subjectA, $seed . '|a'), $actionA);
    $decision = DurableRetryNextAttemptDecision::retry(
        2,
        2,
        '2030-01-01 00:03:00',
        60
    );
    $created = $repository->supersedeAndCreateNextGeneration(
        $claimedA,
        $decision,
        '2030-01-01 00:02:00'
    );
    $assert($created->code() === DurableRetryNextGenerationPersistenceResult::CREATED, 'real created');
    $historical = $created->superseded();
    $successor = $created->successor();
    $assert($historical !== null && $successor !== null, 'real evidence');
    $assert($historical->id() !== $successor->id(), 'real new autoincrement');
    $assert($historical->generation() === 1 && $successor->generation() === 2, 'real generations');
    $assert(
        $historical->toArray()['attempt_number'] === 1
            && $successor->toArray()['attempt_number'] === 2,
        'real attempts'
    );
    $assert(
        $historical->toArray()['active_slot'] === null
            && $successor->toArray()['active_slot'] === 1,
        'real active slot transfer'
    );
    $assert($historical->toArray()['scheduled_action_id'] === $actionA, 'real historical action');
    $assert($successor->toArray()['scheduled_action_id'] === null, 'real successor action absent');
    $assert($historical->toArray()['claimed_at'] === '2030-01-01 00:01:00', 'real claim preserved');
    $assert($successor->toArray()['claimed_at'] === null, 'real successor unclaimed');
    $assert($historical->toArray()['terminal_at'] === '2030-01-01 00:02:00', 'real terminal timestamp');
    $assert($successor->toArray()['created_at'] === '2030-01-01 00:02:00', 'real creation timestamp');
    $assert(
        $repository->findById($historical->id())->snapshot()?->status() === 'superseded',
        'real historical row committed'
    );
    $assert(
        $repository->findById($successor->id())->snapshot()?->status() === 'dispatching',
        'real successor row committed'
    );

    $repeated = $repository->supersedeAndCreateNextGeneration(
        $claimedA,
        $decision,
        '2030-01-01 00:02:00'
    );
    $assert(
        $repeated->code() === DurableRetryNextGenerationPersistenceResult::CONCURRENT_CONVERGENCE,
        'real repeated caller converges'
    );
    $countA = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE stage = %s AND subject_id = %d",
        'business_completion',
        $subjectA
    ));
    $assert($countA === 2, 'real single successor');

    $claimedB = $claim($repository, $initial($subjectB, $seed . '|b'), $actionB);
    $collisionFields = [
        'public_id' => hash('sha256', "next-generation-public|{$seed}|collision"),
        'stage' => 'business_completion',
        'subject_id' => $subjectB,
        'completion_id' => $subjectB + 100,
        'generation' => 2,
        'attempt_number' => 2,
        'scheduled_for' => '2030-01-01 00:03:00',
        'scheduled_action_id' => null,
        'dispatch_token_hash' => hash('sha256', "next-generation-token|{$seed}|collision"),
        'status' => 'failed',
        'active_slot' => null,
        'version' => 1,
        'reason_code' => 'scheduling_failed',
        'dispatched_at' => null,
        'claimed_at' => null,
        'consumed_at' => null,
        'terminal_at' => '2030-01-01 00:01:30',
        'created_at' => '2030-01-01 00:00:00',
        'updated_at' => '2030-01-01 00:01:30',
    ];
    $columns = array_keys($collisionFields);
    $tokens = [];
    $values = [];
    foreach ($collisionFields as $value) {
        if ($value === null) {
            $tokens[] = 'NULL';
        } elseif (is_int($value)) {
            $tokens[] = '%d';
            $values[] = $value;
        } else {
            $tokens[] = '%s';
            $values[] = $value;
        }
    }
    $insertedCollision = $wpdb->query($wpdb->prepare(
        "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $tokens) . ')',
        ...$values
    ));
    $assert($insertedCollision === 1, 'real conflicting inactive generation fixture');

    $collision = $repository->supersedeAndCreateNextGeneration(
        $claimedB,
        $decision,
        '2030-01-01 00:02:00'
    );
    $assert(
        $collision->code() === DurableRetryNextGenerationPersistenceResult::DURABLE_INCONSISTENCY,
        'real incompatible identity collision classified'
    );
    $afterRollback = $repository->findById($claimedB->id())->snapshot();
    $assert($afterRollback?->status() === 'claimed', 'real update rolled back');
    $assert($afterRollback?->toArray()['active_slot'] === 1, 'real slot rollback');
    $countB = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE stage = %s AND subject_id = %d",
        'business_completion',
        $subjectB
    ));
    $assert($countB === 2, 'real no partial successor');

    $staleFields = $claimedB->toArray();
    $staleFields['version'] = 2;
    $stale = DurableRetryScheduleSnapshot::fromArray($staleFields);
    $staleResult = $repository->supersedeAndCreateNextGeneration(
        $stale,
        $decision,
        '2030-01-01 00:02:00'
    );
    $assert(
        in_array($staleResult->code(), [
            DurableRetryNextGenerationPersistenceResult::CAS_CONFLICT,
            DurableRetryNextGenerationPersistenceResult::DURABLE_INCONSISTENCY,
        ], true),
        'real stale version cannot write'
    );
    $assert(
        $repository->findById($claimedB->id())->snapshot()?->status() === 'claimed',
        'real stale CAS leaves historical intact'
    );
} finally {
    $placeholders = implode(', ', array_fill(0, count($subjects), '%d'));
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$table} WHERE stage = %s AND subject_id IN ({$placeholders})",
        'business_completion',
        ...$subjects
    ));
    $wpdb->suppress_errors($previousSuppressErrors);
}

$remaining = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE stage = %s AND subject_id IN (%d, %d)",
    'business_completion',
    $subjectA,
    $subjectB
));
$assert($remaining === 0, 'real fixtures fully removed');
$actionSchedulerTables = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM information_schema.tables"
        . " WHERE table_schema = DATABASE() AND table_name LIKE '%actionscheduler%'"
);
$assert($actionSchedulerTables >= 0, 'harness does not depend on Action Scheduler tables');

echo "durable retry next generation repository mysql: {$assertions} assertions\n";
