<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextAttemptDecision;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingFailure;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingPolicy;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStatus;

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
    } catch (InvalidArgumentException | TypeError) {
        $assert(true, $message);
    }
};
$failure = static fn (
    string $classification,
    string $code,
    ?int $attempt
): DurableRetryProcessingFailure => new DurableRetryProcessingFailure(
    $classification,
    $code,
    $attempt
);

$withoutAttempt = DurableRetryProcessingResult::outcomeUncertain();
$assert($withoutAttempt->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'uncertain classification without attempt');
$assert($withoutAttempt->confirmedAttemptNumber() === null, 'uncertain exposes null');
$assert(! $withoutAttempt->hasConfirmedAttemptNumber(), 'uncertain reports absent attempt');
$assert($withoutAttempt->failure()?->failureCode() === DurableRetryProcessingFailure::TECHNICAL_OUTCOME_UNCERTAIN, 'uncertain factory closes failure code');
$assert(! $withoutAttempt->succeededProcessing(), 'uncertain is not success');

foreach ([1, 5] as $attempt) {
    $result = DurableRetryProcessingResult::outcomeUncertain($attempt);
    $assert($result->confirmedAttemptNumber() === $attempt, "uncertain retains attempt {$attempt}");
    $assert($result->hasConfirmedAttemptNumber(), "uncertain reports attempt {$attempt}");
}
foreach ([0, -1, 6] as $attempt) {
    $rejects(
        static fn () => DurableRetryProcessingResult::outcomeUncertain($attempt),
        "uncertain rejects attempt {$attempt}"
    );
}
$rejects(
    static fn () => DurableRetryProcessingResult::succeeded(null),
    'success rejects null attempt'
);

$uncertainFailure = $failure(
    DurableRetryProcessingFailure::OUTCOME_UNCERTAIN,
    DurableRetryProcessingFailure::TECHNICAL_OUTCOME_UNCERTAIN,
    null
);
$assert($uncertainFailure->confirmedAttemptNumber() === null, 'failure exposes null');
$assert(! $uncertainFailure->hasConfirmedAttemptNumber(), 'failure reports absent attempt');
$knownUncertainFailure = $failure(
    DurableRetryProcessingFailure::OUTCOME_UNCERTAIN,
    DurableRetryProcessingFailure::TECHNICAL_OUTCOME_UNCERTAIN,
    3
);
$assert($knownUncertainFailure->confirmedAttemptNumber() === 3, 'failure retains known attempt');
$assert($knownUncertainFailure->hasConfirmedAttemptNumber(), 'failure reports known attempt');

foreach ([
    [DurableRetryProcessingFailure::RETRYABLE_FAILURE, DurableRetryProcessingFailure::CONFIRMED_RETRYABLE_FAILURE],
    [DurableRetryProcessingFailure::TERMINAL_FAILURE, DurableRetryProcessingFailure::CONFIRMED_TERMINAL_FAILURE],
] as [$classification, $code]) {
    $rejects(
        static fn () => $failure($classification, $code, null),
        "{$classification} rejects null"
    );
}
$rejects(
    static fn () => $failure(
        DurableRetryProcessingFailure::OUTCOME_UNCERTAIN,
        DurableRetryProcessingFailure::CONFIRMED_RETRYABLE_FAILURE,
        null
    ),
    'uncertain rejects retryable code'
);
foreach ([0, 6] as $attempt) {
    $rejects(
        static fn () => $failure(
            DurableRetryProcessingFailure::OUTCOME_UNCERTAIN,
            DurableRetryProcessingFailure::TECHNICAL_OUTCOME_UNCERTAIN,
            $attempt
        ),
        "failure rejects attempt {$attempt}"
    );
}

$fields = [
    'id' => 70,
    'public_id' => str_repeat('a', 64),
    'stage' => 'business_completion',
    'subject_id' => 800,
    'completion_id' => 700,
    'generation' => 7,
    'attempt_number' => 2,
    'scheduled_for' => '2030-01-01 00:01:00',
    'scheduled_action_id' => 900,
    'dispatch_token_hash' => str_repeat('b', 64),
    'status' => DurableRetryStatus::CLAIMED,
    'active_slot' => 1,
    'version' => 3,
    'reason_code' => 'retryable_failure',
    'dispatched_at' => '2030-01-01 00:00:30',
    'claimed_at' => '2030-01-01 00:01:00',
    'consumed_at' => null,
    'terminal_at' => null,
    'created_at' => '2030-01-01 00:00:00',
    'updated_at' => '2030-01-01 00:01:00',
];
$claimed = DurableRetryScheduleSnapshot::fromArray($fields);
$policy = new DurableRetryProcessingPolicy();
$uncertain = $policy->decideNextAttempt(
    $claimed,
    $uncertainFailure,
    '2030-01-01 00:02:00'
);
$assert($uncertain->code() === DurableRetryNextAttemptDecision::UNCERTAIN, 'null attempt decides uncertain');
$assert($uncertain->finalStatus() === DurableRetryStatus::ORPHANED, 'null attempt closes orphaned');
$assert($uncertain->reasonCode() === 'processing_outcome_uncertain', 'null attempt uses normative reason');
$assert($uncertain->interventionRequired(), 'null attempt requires intervention');
$assert(! $uncertain->createsNextGeneration(), 'null attempt creates no generation');
$assert($uncertain->nextGeneration() === null, 'null attempt has no next generation');
$assert($uncertain->nextAttemptNumber() === null, 'null attempt has no next attempt');
$assert($uncertain->backoffSeconds() === null, 'null attempt has no backoff');
$assert($uncertain->scheduledForUtc() === null, 'null attempt has no schedule');

$known = $policy->decideNextAttempt(
    $claimed,
    $knownUncertainFailure,
    '2030-01-01 00:02:00'
);
$assert($known->code() === DurableRetryNextAttemptDecision::UNCERTAIN, 'known matching attempt remains uncertain');
$assert(serialize($known) === serialize($uncertain), 'uncertain decision independent of optional evidence');
$rejects(
    static fn () => $policy->decideNextAttempt(
        $claimed,
        $failure(
            DurableRetryProcessingFailure::OUTCOME_UNCERTAIN,
            DurableRetryProcessingFailure::TECHNICAL_OUTCOME_UNCERTAIN,
            4
        ),
        '2030-01-01 00:02:00'
    ),
    'contradictory uncertain attempt rejected'
);

$retry = $policy->decideNextAttempt(
    $claimed,
    $failure(
        DurableRetryProcessingFailure::RETRYABLE_FAILURE,
        DurableRetryProcessingFailure::CONFIRMED_RETRYABLE_FAILURE,
        3
    ),
    '2030-01-01 00:02:00'
);
$assert($retry->code() === DurableRetryNextAttemptDecision::RETRY, 'retryable behavior retained');
$assert($retry->backoffSeconds() === 240, 'retryable backoff retained');
$terminal = $policy->decideNextAttempt(
    $claimed,
    $failure(
        DurableRetryProcessingFailure::TERMINAL_FAILURE,
        DurableRetryProcessingFailure::CONFIRMED_TERMINAL_FAILURE,
        3
    ),
    '2030-01-01 00:02:00'
);
$assert($terminal->code() === DurableRetryNextAttemptDecision::TERMINAL, 'terminal behavior retained');
$assert($terminal->finalStatus() === DurableRetryStatus::FAILED, 'terminal closure retained');
$first = $policy->decideNextAttempt($claimed, $uncertainFailure, '2030-01-01 00:02:00');
$second = $policy->decideNextAttempt($claimed, $uncertainFailure, '2030-01-01 00:02:00');
$assert(serialize($first) === serialize($second), 'nullable uncertainty is deterministic');

foreach ([
    DurableRetryProcessingResult::class,
    DurableRetryProcessingFailure::class,
] as $class) {
    $reflection = new ReflectionClass($class);
    $assert($reflection->isFinal(), "{$class} final");
    foreach ($reflection->getProperties() as $property) {
        $assert($property->isReadOnly(), "{$class} {$property->getName()} readonly");
    }
}

echo "durable retry nullable processing attempt: {$assertions} assertions\n";
