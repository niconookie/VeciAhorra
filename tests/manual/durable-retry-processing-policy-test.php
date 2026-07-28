<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Modules/Orders/Contracts/DurableRetryProcessingPolicyInterface.php';
require_once __DIR__ . '/../../app/Modules/Orders/Domain/DurableRetry/DurableRetryStatus.php';
require_once __DIR__ . '/../../app/Modules/Orders/Domain/DurableRetry/DurableRetryStage.php';
require_once __DIR__ . '/../../app/Modules/Orders/Domain/DurableRetry/DurableRetryReason.php';
require_once __DIR__ . '/../../app/Modules/Orders/Domain/DurableRetry/DurableRetryScheduleSnapshot.php';
require_once __DIR__ . '/../../app/Modules/Orders/Domain/DurableRetry/DurableRetryProcessingFailure.php';
require_once __DIR__ . '/../../app/Modules/Orders/Domain/DurableRetry/DurableRetryProcessingResult.php';
require_once __DIR__ . '/../../app/Modules/Orders/Domain/DurableRetry/DurableRetryNextAttemptDecision.php';
require_once __DIR__ . '/../../app/Modules/Orders/Domain/DurableRetry/DurableRetryProcessingPolicy.php';

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextAttemptDecision as Decision;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingFailure as Failure;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingPolicy;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingResult as ProcessingResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryReason;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStatus;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$rejects = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (InvalidArgumentException | TypeError) {
        $assert(true, $message);
    }
};
$fields = static fn (
    int $attempt = 0,
    int $generation = 1,
    string $status = DurableRetryStatus::CLAIMED,
    string $stage = DurableRetryStage::BUSINESS_COMPLETION
): array => [
    'id' => 17,
    'public_id' => str_repeat('a', 64),
    'stage' => $stage,
    'subject_id' => 29,
    'completion_id' => $stage === DurableRetryStage::RECONCILIATION ? 29 : null,
    'generation' => $generation,
    'attempt_number' => $attempt,
    'scheduled_for' => '2030-01-01 00:00:00',
    'scheduled_action_id' => 101,
    'dispatch_token_hash' => str_repeat('b', 64),
    'status' => $status,
    'active_slot' => DurableRetryStatus::isActive($status) ? 1 : null,
    'version' => 3,
    'reason_code' => in_array($status, [
        DurableRetryStatus::SCHEDULED,
        DurableRetryStatus::CLAIMED,
    ], true) ? DurableRetryReason::RETRYABLE_FAILURE
        : DurableRetryReason::PROCESSING_TERMINAL_FAILURE,
    'dispatched_at' => '2030-01-01 00:01:00',
    'claimed_at' => $status === DurableRetryStatus::CLAIMED
        ? '2030-01-01 00:02:00' : null,
    'consumed_at' => null,
    'terminal_at' => DurableRetryStatus::isActive($status)
        ? null : '2030-01-01 00:03:00',
    'created_at' => '2029-12-31 23:59:00',
    'updated_at' => $status === DurableRetryStatus::CLAIMED
        ? '2030-01-01 00:02:00' : '2030-01-01 00:03:00',
];
$snapshot = static fn (
    int $attempt = 0,
    int $generation = 1,
    string $status = DurableRetryStatus::CLAIMED,
    string $stage = DurableRetryStage::BUSINESS_COMPLETION
): DurableRetryScheduleSnapshot => DurableRetryScheduleSnapshot::fromArray(
    $fields($attempt, $generation, $status, $stage)
);
$failure = static fn (string $classification, int $attempt): Failure => new Failure(
    $classification,
    match ($classification) {
        Failure::RETRYABLE_FAILURE => Failure::CONFIRMED_RETRYABLE_FAILURE,
        Failure::TERMINAL_FAILURE => Failure::CONFIRMED_TERMINAL_FAILURE,
        Failure::OUTCOME_UNCERTAIN => Failure::TECHNICAL_OUTCOME_UNCERTAIN,
    },
    $attempt
);
$policy = new DurableRetryProcessingPolicy();

foreach ([
    DurableRetryReason::PROCESSING_ATTEMPTS_EXHAUSTED => DurableRetryStatus::FAILED,
    DurableRetryReason::PROCESSING_TERMINAL_FAILURE => DurableRetryStatus::FAILED,
    DurableRetryReason::PROCESSING_OUTCOME_UNCERTAIN => DurableRetryStatus::ORPHANED,
] as $reason => $validStatus) {
    DurableRetryReason::assertForStatus($reason, $validStatus);
    $assert(in_array($reason, DurableRetryReason::all(), true), "{$reason} catalogued");
    foreach (DurableRetryStatus::all() as $status) {
        if ($status === $validStatus) {
            continue;
        }
        $rejects(
            static fn () => DurableRetryReason::assertForStatus($reason, $status),
            "{$reason} rejects {$status}"
        );
    }
}
foreach ([
    DurableRetryReason::SCHEDULING_FAILED => DurableRetryStatus::FAILED,
    DurableRetryReason::DISPATCH_RECOVERY_EXHAUSTED => DurableRetryStatus::FAILED,
    DurableRetryReason::CALLBACK_REJECTED => DurableRetryStatus::FAILED,
    DurableRetryReason::RETRYABLE_FAILURE => DurableRetryStatus::CLAIMED,
] as $reason => $status) {
    DurableRetryReason::assertForStatus($reason, $status);
    $assert(true, "{$reason} remains compatible");
}
$rejects(
    static fn () => DurableRetryReason::assertForStatus('unknown', DurableRetryStatus::FAILED),
    'unknown reason rejected'
);

$success = ProcessingResult::succeeded(1);
$assert($success->classification() === ProcessingResult::SUCCEEDED, 'success classification');
$assert($success->confirmedAttemptNumber() === 1 && $success->failure() === null, 'success data');
$assert($success->succeededProcessing(), 'success flag');
foreach ([
    Failure::RETRYABLE_FAILURE,
    Failure::TERMINAL_FAILURE,
    Failure::OUTCOME_UNCERTAIN,
] as $classification) {
    $typedFailure = $failure($classification, 2);
    $result = ProcessingResult::failed($typedFailure);
    $assert($result->classification() === $classification, "{$classification} result");
    $assert($result->failure() === $typedFailure, "{$classification} retains closed failure");
    $assert(! $result->succeededProcessing(), "{$classification} not success");
}
$assert(Failure::classifications() === [
    Failure::RETRYABLE_FAILURE,
    Failure::TERMINAL_FAILURE,
    Failure::OUTCOME_UNCERTAIN,
], 'failure classification catalog');
$assert(Failure::failureCodes() === [
    Failure::CONFIRMED_RETRYABLE_FAILURE,
    Failure::CONFIRMED_TERMINAL_FAILURE,
    Failure::TECHNICAL_OUTCOME_UNCERTAIN,
], 'failure code catalog');

foreach ([
    ['unknown', Failure::CONFIRMED_RETRYABLE_FAILURE, 1],
    [Failure::RETRYABLE_FAILURE, 'unknown', 1],
    [Failure::TERMINAL_FAILURE, Failure::CONFIRMED_RETRYABLE_FAILURE, 1],
    [Failure::RETRYABLE_FAILURE, Failure::CONFIRMED_RETRYABLE_FAILURE, 0],
    [Failure::RETRYABLE_FAILURE, Failure::CONFIRMED_RETRYABLE_FAILURE, -1],
    [Failure::RETRYABLE_FAILURE, Failure::CONFIRMED_RETRYABLE_FAILURE, 6],
] as [$classification, $code, $attempt]) {
    $rejects(
        static fn () => new Failure($classification, $code, $attempt),
        'invalid failure tuple rejected'
    );
}
foreach (['1', 1.0, true] as $invalidAttempt) {
    $rejects(
        static fn () => new Failure(
            Failure::RETRYABLE_FAILURE,
            Failure::CONFIRMED_RETRYABLE_FAILURE,
            $invalidAttempt
        ),
        'non-integer attempt rejected'
    );
    $rejects(
        static fn () => ProcessingResult::succeeded($invalidAttempt),
        'non-integer success attempt rejected'
    );
}
$beforeFailure = serialize($failure(Failure::RETRYABLE_FAILURE, 1));
$typedFailure = $failure(Failure::RETRYABLE_FAILURE, 1);
$assert(serialize($typedFailure) === $beforeFailure, 'failure immutable');

$table = [
    1 => [0, 60, '2030-01-01 00:01:00'],
    2 => [1, 120, '2030-01-01 00:02:00'],
    3 => [2, 240, '2030-01-01 00:04:00'],
    4 => [3, 480, '2030-01-01 00:08:00'],
];
foreach ($table as $confirmed => [$persisted, $backoff, $scheduledFor]) {
    $claimed = $snapshot($persisted, 7);
    $before = serialize($claimed);
    $decision = $policy->decideNextAttempt(
        $claimed,
        $failure(Failure::RETRYABLE_FAILURE, $confirmed),
        '2030-01-01 00:00:00'
    );
    $assert($decision->code() === Decision::RETRY, "attempt {$confirmed} retries");
    $assert($decision->nextGeneration() === 8, "attempt {$confirmed} generation +1");
    $assert($decision->nextAttemptNumber() === $confirmed, "attempt {$confirmed} snapshot");
    $assert($decision->backoffSeconds() === $backoff, "attempt {$confirmed} backoff");
    $assert($decision->scheduledForUtc() === $scheduledFor, "attempt {$confirmed} UTC");
    $assert($decision->createsNextGeneration(), "attempt {$confirmed} creates generation");
    $assert(serialize($claimed) === $before, "attempt {$confirmed} snapshot immutable");
}

$exhausted = $policy->decideNextAttempt(
    $snapshot(4),
    $failure(Failure::RETRYABLE_FAILURE, 5),
    '2030-01-01 00:00:00'
);
$assert($exhausted->code() === Decision::EXHAUSTED, 'attempt five exhausted');
$assert($exhausted->finalStatus() === DurableRetryStatus::FAILED, 'exhausted failed');
$assert(
    $exhausted->reasonCode() === DurableRetryReason::PROCESSING_ATTEMPTS_EXHAUSTED,
    'exhausted reason'
);
$assert(! $exhausted->createsNextGeneration(), 'exhausted no generation');
$assert($exhausted->backoffSeconds() === null, 'exhausted no backoff');

$terminal = $policy->decideNextAttempt(
    $snapshot(1),
    $failure(Failure::TERMINAL_FAILURE, 2),
    '2030-01-01 00:00:00'
);
$assert($terminal->code() === Decision::TERMINAL, 'terminal decision');
$assert($terminal->finalStatus() === DurableRetryStatus::FAILED, 'terminal failed');
$assert(
    $terminal->reasonCode() === DurableRetryReason::PROCESSING_TERMINAL_FAILURE,
    'terminal reason'
);
$assert(! $terminal->createsNextGeneration() && $terminal->backoffSeconds() === null, 'terminal closes');

$uncertain = $policy->decideNextAttempt(
    $snapshot(2),
    $failure(Failure::OUTCOME_UNCERTAIN, 3),
    '2030-01-01 00:00:00'
);
$assert($uncertain->code() === Decision::UNCERTAIN, 'uncertain decision');
$assert($uncertain->finalStatus() === DurableRetryStatus::ORPHANED, 'uncertain orphaned');
$assert(
    $uncertain->reasonCode() === DurableRetryReason::PROCESSING_OUTCOME_UNCERTAIN,
    'uncertain reason'
);
$assert($uncertain->interventionRequired(), 'uncertain intervention');
$assert(! $uncertain->createsNextGeneration(), 'uncertain no generation');

foreach ([
    ['2030-01-31 23:59:30', '2030-02-01 00:00:30'],
    ['2030-12-31 23:59:30', '2031-01-01 00:00:30'],
    ['2032-02-28 23:59:30', '2032-02-29 00:00:30'],
] as [$now, $expected]) {
    $decision = $policy->decideNextAttempt(
        $snapshot(),
        $failure(Failure::RETRYABLE_FAILURE, 1),
        $now
    );
    $assert($decision->scheduledForUtc() === $expected, "UTC boundary {$now}");
}

$originalTimezone = date_default_timezone_get();
date_default_timezone_set('Pacific/Kiritimati');
$farEast = $policy->decideNextAttempt(
    $snapshot(),
    $failure(Failure::RETRYABLE_FAILURE, 1),
    '2030-01-01 00:00:00'
);
date_default_timezone_set('America/Santiago');
$west = $policy->decideNextAttempt(
    $snapshot(),
    $failure(Failure::RETRYABLE_FAILURE, 1),
    '2030-01-01 00:00:00'
);
date_default_timezone_set($originalTimezone);
$assert($farEast->scheduledForUtc() === $west->scheduledForUtc(), 'PHP timezone independent');
$assert($farEast->scheduledForUtc() === '2030-01-01 00:01:00', 'WordPress timezone irrelevant');

foreach ([
    ' 2030-01-01 00:00:00',
    '2030-01-01 00:00:00 ',
    '2030-01-01T00:00:00Z',
    '2030-01-01 00:00:00+00:00',
    '2030-02-30 00:00:00',
    '2030-01-01',
    '1234567890',
    '1969-12-31 23:59:59',
] as $invalidDate) {
    $rejects(
        static fn () => $policy->decideNextAttempt(
            $snapshot(),
            $failure(Failure::RETRYABLE_FAILURE, 1),
            $invalidDate
        ),
        "invalid UTC rejected: {$invalidDate}"
    );
}
$rejects(
    static fn () => $policy->decideNextAttempt(
        $snapshot(status: DurableRetryStatus::SCHEDULED),
        $failure(Failure::RETRYABLE_FAILURE, 1),
        '2030-01-01 00:00:00'
    ),
    'non-claimed rejected'
);
$rejects(
    static fn () => $policy->decideNextAttempt(
        $snapshot(0),
        $failure(Failure::RETRYABLE_FAILURE, 2),
        '2030-01-01 00:00:00'
    ),
    'incompatible confirmed attempt rejected'
);
$rejects(
    static fn () => $policy->decideNextAttempt(
        $snapshot(0, PHP_INT_MAX),
        $failure(Failure::RETRYABLE_FAILURE, 1),
        '2030-01-01 00:00:00'
    ),
    'generation overflow rejected'
);
$rejects(
    static fn () => $policy->decideNextAttempt(
        $snapshot(),
        $failure(Failure::RETRYABLE_FAILURE, 1),
        '9999-12-31 23:59:59'
    ),
    'timestamp overflow rejected'
);
$invalidSnapshot = $fields();
$invalidSnapshot['generation'] = 0;
$rejects(
    static fn () => DurableRetryScheduleSnapshot::fromArray($invalidSnapshot),
    'invalid generation snapshot rejected by domain'
);
$invalidSnapshot = $fields();
$invalidSnapshot['stage'] = 'unknown';
$rejects(
    static fn () => DurableRetryScheduleSnapshot::fromArray($invalidSnapshot),
    'invalid stage snapshot rejected by domain'
);
$first = $policy->decideNextAttempt(
    $snapshot(2, 11),
    $failure(Failure::RETRYABLE_FAILURE, 3),
    '2030-06-01 12:00:00'
);
$second = $policy->decideNextAttempt(
    $snapshot(2, 11),
    $failure(Failure::RETRYABLE_FAILURE, 3),
    '2030-06-01 12:00:00'
);
$assert(serialize($first) === serialize($second), 'identical input deterministic');

foreach (DurableRetryStage::all() as $stage) {
    $decision = $policy->decideNextAttempt(
        $snapshot(stage: $stage),
        $failure(Failure::RETRYABLE_FAILURE, 1),
        '2030-01-01 00:00:00'
    );
    $assert($decision->code() === Decision::RETRY, "{$stage} uses common policy");
}

echo "durable retry processing policy: {$assertions} assertions\n";
