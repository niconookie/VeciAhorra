<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryReason;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStatus;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

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

$assert(DurableRetryStage::all() === [
    'reconciliation',
    'business_completion',
    'delivery_completion',
    'fulfillment_completion',
], 'exact stage allowlist');
$assert(DurableRetryStatus::all() === [
    'dispatching',
    'scheduled',
    'claimed',
    'consumed',
    'superseded',
    'cancelled',
    'failed',
    'orphaned',
], 'exact status allowlist');
$assert(DurableRetryReason::all() === [
    'retryable_failure',
    'stage_became_terminal',
    'retry_consumed',
    'superseded_generation',
    'cancelled_by_authority',
    'scheduling_failed',
    'dispatch_recovery_exhausted',
    'callback_rejected',
    'external_action_missing',
    'external_action_mismatch',
    'inconsistency_requires_remediation',
], 'exact reason allowlist');
$assert(DurableRetryStatus::active() === [
    'dispatching',
    'scheduled',
    'claimed',
], 'claimed retains active slot');

$base = static fn (): array => [
    'public_id' => str_repeat('a', 64),
    'stage' => 'business_completion',
    'subject_id' => 10,
    'completion_id' => null,
    'generation' => 1,
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

DurableRetryScheduleSnapshot::validateInitial($base());
$assert(true, 'valid initial dispatching snapshot');

$snapshots = [
    'scheduled' => [
        'scheduled_action_id' => 100,
        'status' => 'scheduled',
        'dispatched_at' => '2026-07-28 12:01:00',
        'updated_at' => '2026-07-28 12:01:00',
    ],
    'claimed' => [
        'scheduled_action_id' => 100,
        'status' => 'claimed',
        'dispatched_at' => '2026-07-28 12:01:00',
        'claimed_at' => '2026-07-28 12:02:00',
        'updated_at' => '2026-07-28 12:02:00',
    ],
    'consumed' => [
        'scheduled_action_id' => 100,
        'status' => 'consumed',
        'active_slot' => null,
        'reason_code' => 'retry_consumed',
        'dispatched_at' => '2026-07-28 12:01:00',
        'claimed_at' => '2026-07-28 12:02:00',
        'consumed_at' => '2026-07-28 12:03:00',
        'updated_at' => '2026-07-28 12:03:00',
        'terminal_at' => '2026-07-28 12:03:00',
    ],
    'superseded' => [
        'status' => 'superseded',
        'active_slot' => null,
        'reason_code' => 'superseded_generation',
        'updated_at' => '2026-07-28 12:03:00',
        'terminal_at' => '2026-07-28 12:03:00',
    ],
    'cancelled' => [
        'status' => 'cancelled',
        'active_slot' => null,
        'reason_code' => 'cancelled_by_authority',
        'updated_at' => '2026-07-28 12:03:00',
        'terminal_at' => '2026-07-28 12:03:00',
    ],
    'failed' => [
        'status' => 'failed',
        'active_slot' => null,
        'reason_code' => 'scheduling_failed',
        'updated_at' => '2026-07-28 12:03:00',
        'terminal_at' => '2026-07-28 12:03:00',
    ],
    'orphaned' => [
        'status' => 'orphaned',
        'active_slot' => null,
        'reason_code' => 'external_action_missing',
        'updated_at' => '2026-07-28 12:03:00',
        'terminal_at' => '2026-07-28 12:03:00',
    ],
];
foreach ($snapshots as $status => $changes) {
    DurableRetryScheduleSnapshot::validate(array_replace($base(), $changes));
    $assert(true, "valid {$status} matrix");
}

foreach (['invented', 'Reconciliation', ' reconciliation'] as $invalid) {
    $rejects(
        static fn () => DurableRetryStage::assert($invalid),
        "reject stage {$invalid}"
    );
}
foreach (['invented', 'Scheduled', 'scheduled '] as $invalid) {
    $rejects(
        static fn () => DurableRetryStatus::assert($invalid),
        "reject status {$invalid}"
    );
}
$rejects(
    static fn () => DurableRetryReason::assertForStatus('retry_consumed', 'scheduled'),
    'reject globally valid reason for wrong status'
);
$rejects(
    static fn () => DurableRetryReason::assertForStatus('invented', 'failed'),
    'reject unknown reason'
);

foreach ([
    -1,
    1.5,
    '1e2',
    ' 1',
    '1 ',
    '01',
    true,
    [],
] as $invalidInteger) {
    $candidate = $base();
    $candidate['subject_id'] = $invalidInteger;
    $rejects(
        static fn () => DurableRetryScheduleSnapshot::validate($candidate),
        'reject non-canonical integer'
    );
}
foreach ([
    ['generation', 0],
    ['attempt_number', -1],
    ['version', 0],
    ['scheduled_action_id', 0],
] as [$field, $invalid]) {
    $candidate = $base();
    $candidate[$field] = $invalid;
    $rejects(
        static fn () => DurableRetryScheduleSnapshot::validate($candidate),
        "reject invalid {$field}"
    );
}

foreach (['', str_repeat('a', 63), str_repeat('A', 64), str_repeat('g', 64)] as $id) {
    $candidate = $base();
    $candidate['public_id'] = $id;
    $rejects(
        static fn () => DurableRetryScheduleSnapshot::validate($candidate),
        'reject invalid public id'
    );
}
$candidate = $base();
$candidate['dispatch_token_hash'] = str_repeat('0', 63);
$rejects(
    static fn () => DurableRetryScheduleSnapshot::validate($candidate),
    'reject invalid dispatch token hash'
);

$candidate = $base();
$candidate['stage'] = 'reconciliation';
$candidate['completion_id'] = null;
$rejects(
    static fn () => DurableRetryScheduleSnapshot::validate($candidate),
    'reconciliation requires matching completion id'
);
$candidate['completion_id'] = 11;
$rejects(
    static fn () => DurableRetryScheduleSnapshot::validate($candidate),
    'reconciliation rejects mismatching completion id'
);
$candidate['completion_id'] = 10;
DurableRetryScheduleSnapshot::validate($candidate);
$assert(true, 'reconciliation accepts matching identity');

foreach ([
    '2026-7-28 12:00:00',
    '2026-07-28T12:00:00Z',
    '2026-02-30 12:00:00',
    '2026-07-28 12:00:00.000',
] as $timestamp) {
    $candidate = $base();
    $candidate['scheduled_for'] = $timestamp;
    $rejects(
        static fn () => DurableRetryScheduleSnapshot::validate($candidate),
        'reject non-contractual UTC timestamp'
    );
}

$invalidCases = [];
$invalidCases[] = ['active_slot' => null];
$invalidCases[] = ['scheduled_action_id' => 10];
$invalidCases[] = ['dispatched_at' => '2026-07-28 12:01:00'];
$invalidCases[] = ['claimed_at' => '2026-07-28 12:01:00'];
$invalidCases[] = ['terminal_at' => '2026-07-28 12:01:00'];
$invalidCases[] = ['reason_code' => 'retry_consumed'];
$invalidCases[] = ['created_at' => '2026-07-28 12:01:00'];
foreach ($invalidCases as $changes) {
    $candidate = array_replace($base(), $changes);
    $rejects(
        static fn () => DurableRetryScheduleSnapshot::validate($candidate),
        'reject contradictory dispatching snapshot'
    );
}

$scheduled = array_replace($base(), $snapshots['scheduled']);
$scheduled['scheduled_action_id'] = null;
$rejects(
    static fn () => DurableRetryScheduleSnapshot::validate($scheduled),
    'scheduled requires external identity'
);
$scheduled = array_replace($base(), $snapshots['scheduled']);
$scheduled['dispatched_at'] = null;
$rejects(
    static fn () => DurableRetryScheduleSnapshot::validate($scheduled),
    'scheduled requires dispatch timestamp'
);
$claimed = array_replace($base(), $snapshots['claimed']);
$claimed['claimed_at'] = null;
$rejects(
    static fn () => DurableRetryScheduleSnapshot::validate($claimed),
    'claimed requires claim timestamp'
);
$terminal = array_replace($base(), $snapshots['failed']);
$terminal['active_slot'] = 1;
$rejects(
    static fn () => DurableRetryScheduleSnapshot::validate($terminal),
    'terminal state rejects active slot'
);

foreach (['scheduled', 'claimed', 'failed'] as $status) {
    $rejects(
        static fn () => DurableRetryScheduleSnapshot::validateInitial(
            array_replace($base(), $snapshots[$status])
        ),
        "initial insertion rejects {$status}"
    );
}

$outOfOrder = array_replace($base(), $snapshots['consumed'], [
    'claimed_at' => '2026-07-28 12:04:00',
    'consumed_at' => '2026-07-28 12:03:00',
]);
$rejects(
    static fn () => DurableRetryScheduleSnapshot::validate($outOfOrder),
    'reject structural timestamp inversion'
);

echo "durable retry schedule domain: {$assertions} assertions\n";
