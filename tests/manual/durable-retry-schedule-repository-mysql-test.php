<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryScheduleRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

global $wpdb;

$table = $wpdb->prefix . 'va_durable_retry_schedules';
$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
if ($found !== $table) {
    echo "durable retry schedule repository mysql: SKIP (table {$table} is absent)\n";
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
$publicA = hash('sha256', 'mysql-durable-retry-a|' . $seed);
$publicB = hash('sha256', 'mysql-durable-retry-b|' . $seed);
$tokenA = hash('sha256', 'mysql-dispatch-a|' . $seed);
$tokenB = hash('sha256', 'mysql-dispatch-b|' . $seed);
$subjectA = random_int(1_000_000_000, 1_500_000_000);
$subjectB = $subjectA + 1;
$actionId = random_int(1_500_000_001, 2_000_000_000);
$initial = static fn (
    string $publicId,
    string $tokenHash,
    int $subjectId
): array => [
    'public_id' => $publicId,
    'stage' => 'business_completion',
    'subject_id' => $subjectId,
    'completion_id' => null,
    'generation' => 1,
    'attempt_number' => 0,
    'scheduled_for' => '2030-01-01 00:05:00',
    'scheduled_action_id' => null,
    'dispatch_token_hash' => $tokenHash,
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
$repository = new DurableRetryScheduleRepository($wpdb);
$ids = [];
$previousSuppressErrors = $wpdb->suppress_errors(true);
$wpdb->query('START TRANSACTION');

try {
    $createdA = $repository->create($initial($publicA, $tokenA, $subjectA));
    $assert($createdA->code() === DurableRetryPersistenceResult::CREATED, 'real first create');
    $assert($createdA->snapshot() !== null, 'real create hydrates');
    $ids[] = $createdA->snapshot()->id();

    $duplicate = $repository->create($initial($publicA, $tokenA, $subjectA));
    $assert(
        $duplicate->code() === DurableRetryPersistenceResult::EXISTING_COMPATIBLE,
        'real duplicate is idempotent'
    );
    $assert($duplicate->snapshot()?->id() === $ids[0], 'real duplicate preserves identity');

    $identity = $repository->findByIdentity('business_completion', $subjectA, 1);
    $assert($identity->snapshot()?->id() === $ids[0], 'real canonical identity read');
    $assert(
        $identity->snapshot()?->toArray()['scheduled_action_id'] === null,
        'real NULL action remains NULL'
    );

    $createdB = $repository->create($initial($publicB, $tokenB, $subjectB));
    $assert($createdB->code() === DurableRetryPersistenceResult::CREATED, 'real second NULL action');
    $ids[] = $createdB->snapshot()->id();
    $assert(
        $createdB->snapshot()?->toArray()['scheduled_action_id'] === null,
        'real multiple NULL actions'
    );

    $attachedA = $repository->associateScheduledAction(
        $ids[0],
        1,
        $actionId,
        '2030-01-01 00:01:00',
        '2030-01-01 00:01:00'
    );
    $assert($attachedA->code() === DurableRetryPersistenceResult::APPLIED, 'real action CAS');
    $assert($attachedA->snapshot()?->version() === 2, 'real action CAS increments version');

    $sameAction = $repository->associateScheduledAction(
        $ids[0],
        1,
        $actionId,
        '2030-01-01 00:01:00',
        '2030-01-01 00:01:00'
    );
    $assert(
        $sameAction->code() === DurableRetryPersistenceResult::ALREADY_APPLIED,
        'real repeated action is immediate idempotence'
    );

    $actionCollision = $repository->associateScheduledAction(
        $ids[1],
        1,
        $actionId,
        '2030-01-01 00:01:00',
        '2030-01-01 00:01:00'
    );
    $assert(
        $actionCollision->code() === DurableRetryPersistenceResult::CONFLICT,
        'real non-null action unique collision'
    );

    $scheduled = $attachedA->snapshot();
    $claimedFields = array_replace($scheduled->toArray(), [
        'status' => 'claimed',
        'version' => 3,
        'claimed_at' => '2030-01-01 00:02:00',
        'updated_at' => '2030-01-01 00:02:00',
    ]);
    $claimed = DurableRetryScheduleSnapshot::fromArray($claimedFields);
    $claimResult = $repository->transition($scheduled, $claimed);
    $assert($claimResult->code() === DurableRetryPersistenceResult::APPLIED, 'real general CAS');
    $assert($claimResult->snapshot()?->version() === 3, 'real CAS persists version three');

    $staleTargetFields = array_replace($scheduled->toArray(), [
        'status' => 'failed',
        'active_slot' => null,
        'version' => 3,
        'reason_code' => 'callback_rejected',
        'terminal_at' => '2030-01-01 00:03:00',
        'updated_at' => '2030-01-01 00:03:00',
    ]);
    $stale = $repository->transition(
        $scheduled,
        DurableRetryScheduleSnapshot::fromArray($staleTargetFields)
    );
    $assert(
        in_array($stale->code(), [
            DurableRetryPersistenceResult::UNEXPECTED_STATE,
            DurableRetryPersistenceResult::AUTHORITY_LOST,
        ], true),
        'real competing stale writer loses authority'
    );
} finally {
    $wpdb->query('ROLLBACK');
    $wpdb->suppress_errors($previousSuppressErrors);
}

$placeholders = implode(', ', array_fill(0, count($ids), '%d'));
$remaining = $ids === []
    ? 0
    : (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE id IN ({$placeholders})",
            ...$ids
        )
    );
$assert($remaining === 0, 'rollback leaves no durable retry fixtures');

echo "durable retry schedule repository mysql: {$assertions} assertions\n";
