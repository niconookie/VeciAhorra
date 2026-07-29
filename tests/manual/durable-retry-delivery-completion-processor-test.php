<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Delivery\Completion\Contracts\DeliveryCompletionAttemptProcessorInterface;
use VeciAhorra\Modules\Delivery\Completion\Contracts\DeliveryCompletionReadAuthorityInterface;
use VeciAhorra\Modules\Delivery\Completion\DTO\DeliveryCompletionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionContext;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingFailure;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Orders\Services\DurableRetryDeliveryCompletionProcessor;

final class DeliveryAttemptDouble implements DeliveryCompletionAttemptProcessorInterface
{
    public array $calls = [];
    public array $queue = [];
    public function process(int $businessCompletionId, string $owner, int $leaseSeconds = 600): DeliveryCompletionResult
    {
        $this->calls[] = func_get_args();
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    }
}
final class DeliveryReadDouble implements DeliveryCompletionReadAuthorityInterface
{
    public array $calls = [];
    public array $queue = [];
    public function findByBusinessCompletion(int $businessCompletionId): ?array
    {
        $this->calls[] = $businessCompletionId;
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) { throw new RuntimeException($message); }
};
$context = static fn (
    string $stage = DurableRetryStage::DELIVERY_COMPLETION,
    ?int $completionId = null,
    int $previous = 0
): DurableRetryExecutionContext => new DurableRetryExecutionContext(
    70, $stage, 80, $completionId, 1, $previous, $previous + 1,
    '2030-01-01 00:01:00'
);
$attempt = static fn (
    string $status,
    string $reason,
    ?int $completionId = 90
): DeliveryCompletionResult => new DeliveryCompletionResult(
    $status, $reason, 80, $completionId
);
$row = static function (
    string $status,
    mixed $attemptNumber = 1,
    ?string $reason = null,
    mixed $id = 90,
    mixed $businessId = 80
): array {
    return [
        'id' => $id,
        'business_completion_id' => $businessId,
        'completion_status' => $status,
        'attempt_count' => $attemptNumber,
        'last_result_code' => $reason,
        'completed_at' => in_array($status, ['completed', 'not_required'], true)
            ? '2030-01-01 00:02:00'
            : null,
    ];
};
$run = static function (
    DeliveryCompletionResult|Throwable $attemptResult,
    array|Throwable|null $authority,
    ?DurableRetryExecutionContext $executionContext = null
) use ($context): array {
    $attempts = new DeliveryAttemptDouble();
    $attempts->queue[] = $attemptResult;
    $reads = new DeliveryReadDouble();
    $reads->queue[] = $authority;
    $processor = new DurableRetryDeliveryCompletionProcessor($attempts, $reads);
    $result = $processor->process($executionContext ?? $context());
    return [$result, $attempts, $reads, $processor];
};

[$success, $attempts, $reads, $processor] = $run(
    $attempt(DeliveryCompletionResult::COMPLETED, 'deliveries_materialized'),
    $row('completed', 1, 'deliveries_materialized')
);
$assert($processor->stage() === DurableRetryStage::DELIVERY_COMPLETION, 'delivery stage');
$assert($success->succeededProcessing(), 'completed succeeds');
$assert($success->confirmedAttemptNumber() === 1, 'success counter');
$assert(count($attempts->calls) === 1 && count($reads->calls) === 1, 'one attempt and reread');
$assert($attempts->calls[0][0] === 80 && $reads->calls[0] === 80, 'business completion subject');
$assert(preg_match('/^worker_[a-f0-9]{32}$/D', $attempts->calls[0][1]) === 1, 'worker id');
$assert($attempts->calls[0][2] === 600, 'lease duration');

foreach ([
    $context(DurableRetryStage::BUSINESS_COMPLETION),
    $context(DurableRetryStage::DELIVERY_COMPLETION, null, 5),
] as $invalidContext) {
    [$invalid, $attempts, $reads] = $run(
        $attempt(DeliveryCompletionResult::COMPLETED, 'deliveries_materialized'),
        $row('completed', 1, 'deliveries_materialized'),
        $invalidContext
    );
    $assert($invalid->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'invalid context uncertain');
    $assert($invalid->confirmedAttemptNumber() === null, 'invalid context nullable');
    $assert($attempts->calls === [] && $reads->calls === [], 'invalid context zero calls');
}

foreach ([
    [null, DeliveryCompletionResult::ALREADY_COMPLETED, 'completed', 'deliveries_materialized'],
    [90, DeliveryCompletionResult::COMPLETED, 'completed', 'deliveries_materialized'],
    [90, DeliveryCompletionResult::NOT_REQUIRED, 'not_required', 'pickup'],
] as [$completionId, $attemptStatus, $status, $reason]) {
    [$completed] = $run(
        $attempt($attemptStatus, $reason),
        $row($status, 1, $reason),
        $context(DurableRetryStage::DELIVERY_COMPLETION, $completionId)
    );
    $assert($completed->succeededProcessing(), "{$status} authority succeeds");
}

[$identityConflict] = $run(
    $attempt(DeliveryCompletionResult::COMPLETED, 'deliveries_materialized'),
    $row('completed', 1, 'deliveries_materialized'),
    $context(DurableRetryStage::DELIVERY_COMPLETION, 91)
);
$assert($identityConflict->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'completion identity conflict');
$assert($identityConflict->confirmedAttemptNumber() === 1, 'identity counter preserved');

foreach (['delivery_verification_failed', 'lease_lost', 'unexpected_failure'] as $reason) {
    [$retryable] = $run(
        $attempt(
            $reason === 'lease_lost'
                ? DeliveryCompletionResult::LEASE_LOST
                : DeliveryCompletionResult::RETRYABLE_FAILURE,
            $reason
        ),
        $row('retryable', 1, $reason)
    );
    $assert($retryable->classification() === DurableRetryProcessingFailure::RETRYABLE_FAILURE, "{$reason} retryable");
}

foreach ([
    ['permanent_failure', 'business_completion_not_completed', DeliveryCompletionResult::PERMANENT_FAILURE],
    ['manual_review', 'fulfillment_snapshot_invalid', DeliveryCompletionResult::MANUAL_REVIEW],
    ['manual_review', 'order_snapshot_invalid', DeliveryCompletionResult::MANUAL_REVIEW],
    ['manual_review', 'snapshot_order_missing', DeliveryCompletionResult::MANUAL_REVIEW],
    ['manual_review', 'snapshot_order_not_paid', DeliveryCompletionResult::MANUAL_REVIEW],
    ['manual_review', 'delivery_identity_conflict', DeliveryCompletionResult::MANUAL_REVIEW],
] as [$status, $reason, $attemptStatus]) {
    [$terminal] = $run($attempt($attemptStatus, $reason), $row($status, 1, $reason));
    $assert($terminal->classification() === DurableRetryProcessingFailure::TERMINAL_FAILURE, "{$reason} terminal");
}

foreach ([
    [$attempt(DeliveryCompletionResult::COMPLETED, 'deliveries_materialized'), $row('pending', 1, null), 1, 'success unconfirmed'],
    [$attempt(DeliveryCompletionResult::RETRYABLE_FAILURE, 'lease_unavailable'), $row('processing', 1, null), 1, 'lease busy'],
    [$attempt(DeliveryCompletionResult::LEASE_LOST, 'lease_lost'), $row('processing', 1, null), 1, 'lease lost'],
    [$attempt(DeliveryCompletionResult::PERMANENT_FAILURE, 'unknown'), $row('permanent_failure', 1, 'unknown'), 1, 'unknown terminal'],
    [$attempt('malformed', 'deliveries_materialized'), $row('completed', 1, 'deliveries_materialized'), 1, 'malformed result'],
    [$attempt(DeliveryCompletionResult::RETRYABLE_FAILURE, 'unexpected_failure'), null, null, 'missing authority'],
    [$attempt(DeliveryCompletionResult::RETRYABLE_FAILURE, 'unexpected_failure'), $row('retryable', 0, 'unexpected_failure'), null, 'zero counter'],
    [$attempt(DeliveryCompletionResult::RETRYABLE_FAILURE, 'unexpected_failure'), $row('retryable', 2, 'unexpected_failure'), 2, 'advanced counter'],
    [$attempt(DeliveryCompletionResult::COMPLETED, 'deliveries_materialized'), $row('completed', 1, 'deliveries_materialized', 90, 81), 1, 'subject conflict'],
] as [$attemptResult, $authority, $counter, $label]) {
    [$uncertain] = $run($attemptResult, $authority);
    $assert($uncertain->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, "{$label} uncertain");
    $assert($uncertain->confirmedAttemptNumber() === $counter, "{$label} counter");
}

[$lostResponse] = $run(
    new PersistenceException('private SQL'),
    $row('completed', 1, 'deliveries_materialized')
);
$assert($lostResponse->succeededProcessing(), 'infrastructure failure with completed evidence succeeds');

[$infrastructure, $attempts, $reads] = $run(
    new PersistenceException('private SQL'),
    $row('processing', 1, null)
);
$assert($infrastructure->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'recognized infrastructure uncertain');
$assert($infrastructure->confirmedAttemptNumber() === 1, 'recognized infrastructure counter');
$assert(count($attempts->calls) === 1 && count($reads->calls) === 1, 'recognized infrastructure rereads once');

[$readFailure] = $run(
    $attempt(DeliveryCompletionResult::RETRYABLE_FAILURE, 'unexpected_failure'),
    new PersistenceException('private SELECT')
);
$assert($readFailure->classification() === DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, 'read failure uncertain');
$assert($readFailure->confirmedAttemptNumber() === null, 'read failure nullable');

$programmingAttempts = new DeliveryAttemptDouble();
$programmingAttempts->queue[] = new LogicException('programming defect');
$programmingReads = new DeliveryReadDouble();
$programmingReads->queue[] = $row('completed', 1, 'deliveries_materialized');
$programmingProcessor = new DurableRetryDeliveryCompletionProcessor($programmingAttempts, $programmingReads);
try {
    $programmingProcessor->process($context());
    $assert(false, 'unknown programming exception must propagate');
} catch (LogicException $exception) {
    $assert($exception->getMessage() === 'programming defect', 'unknown programming exception propagated');
}
$assert($programmingReads->calls === [], 'unknown programming exception does not masquerade as evidence');

echo "durable retry delivery completion processor: {$assertions} assertions\n";
