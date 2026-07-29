<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Delivery\Completion\Contracts\DeliveryCompletionAttemptProcessorInterface;
use VeciAhorra\Modules\Delivery\Completion\Contracts\DeliveryCompletionReadAuthorityInterface;
use VeciAhorra\Modules\Delivery\Completion\DTO\DeliveryCompletionResult;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryScheduleRepositoryInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryStageProcessorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryStageProcessorResolverInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryCoordinationResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextAttemptDecision;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextGenerationPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingPolicy;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Orders\Services\DurableRetryDeliveryCompletionProcessor;
use VeciAhorra\Modules\Orders\Services\DurableRetryExecutor;

final class DeliveryIntegrationAttempts implements DeliveryCompletionAttemptProcessorInterface
{
    public array $queue = [];
    public int $calls = 0;
    public function process(int $businessCompletionId, string $owner, int $leaseSeconds = 600): DeliveryCompletionResult
    {
        ++$this->calls;
        return array_shift($this->queue);
    }
}
final class DeliveryIntegrationReads implements DeliveryCompletionReadAuthorityInterface
{
    public array $queue = [];
    public int $calls = 0;
    public function findByBusinessCompletion(int $businessCompletionId): ?array
    {
        ++$this->calls;
        return array_shift($this->queue);
    }
}
final class DeliveryIntegrationRepository implements DurableRetryScheduleRepositoryInterface
{
    public array $transitions = [];
    public array $successions = [];
    public array $readQueue = [];
    public array $transitionQueue = [];
    public array $successionQueue = [];
    public function create(array $initialFields): DurableRetryPersistenceResult { throw new LogicException(); }
    public function findById(int $id): DurableRetryPersistenceResult { return array_shift($this->readQueue); }
    public function findByIdentity(string $stage, int $subjectId, int $generation): DurableRetryPersistenceResult { throw new LogicException(); }
    public function associateScheduledAction(int $id, int $expectedVersion, int $scheduledActionId, string $dispatchedAt, string $updatedAt): DurableRetryPersistenceResult { throw new LogicException(); }
    public function transition(DurableRetryScheduleSnapshot $expected, DurableRetryScheduleSnapshot $target): DurableRetryPersistenceResult
    {
        $this->transitions[] = [$expected, $target];
        return array_shift($this->transitionQueue);
    }
    public function supersedeAndCreateNextGeneration(DurableRetryScheduleSnapshot $claimed, DurableRetryNextAttemptDecision $decision, string $supersededAtUtc): DurableRetryNextGenerationPersistenceResult
    {
        $this->successions[] = func_get_args();
        return array_shift($this->successionQueue);
    }
}
final class DeliveryIntegrationCoordinator implements DurableRetryExternalScheduleCoordinatorInterface
{
    public array $calls = [];
    public array $queue = [];
    public function coordinate(int $scheduleId, int $generation): DurableRetryCoordinationResult
    {
        $this->calls[] = func_get_args();
        return array_shift($this->queue);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) { throw new RuntimeException($message); }
};
$row = static fn (string $status, int $attempt, ?string $reason): array => [
    'id' => 90,
    'business_completion_id' => 80,
    'completion_status' => $status,
    'attempt_count' => $attempt,
    'last_result_code' => $reason,
    'completed_at' => in_array($status, ['completed', 'not_required'], true)
        ? '2030-01-01 00:02:00'
        : null,
];
$attempt = static fn (string $status, string $reason): DeliveryCompletionResult =>
    new DeliveryCompletionResult($status, $reason, 80, 90);
$schedule = static function (string $status, int $attempt = 0, int $generation = 1, int $id = 70): DurableRetryScheduleSnapshot {
    $terminal = in_array($status, ['consumed', 'failed', 'orphaned', 'superseded'], true);
    return DurableRetryScheduleSnapshot::fromArray([
        'id' => $id,
        'public_id' => str_repeat($id === 70 ? 'a' : 'c', 64),
        'stage' => 'delivery_completion',
        'subject_id' => 80,
        'completion_id' => 90,
        'generation' => $generation,
        'attempt_number' => $attempt,
        'scheduled_for' => '2030-01-01 00:01:00',
        'scheduled_action_id' => $status === 'dispatching' ? null : 900,
        'dispatch_token_hash' => str_repeat($id === 70 ? 'b' : 'd', 64),
        'status' => $status,
        'active_slot' => $terminal ? null : 1,
        'version' => $status === 'scheduled' ? 2 : ($status === 'dispatching' ? 1 : 3),
        'reason_code' => match ($status) {
            'consumed' => 'retry_consumed',
            'failed' => 'processing_terminal_failure',
            'orphaned' => 'processing_outcome_uncertain',
            'superseded' => 'superseded_generation',
            default => 'retryable_failure',
        },
        'dispatched_at' => $status === 'dispatching' ? null : '2030-01-01 00:00:30',
        'claimed_at' => in_array($status, ['claimed', 'consumed', 'failed', 'orphaned', 'superseded'], true) ? '2030-01-01 00:01:00' : null,
        'consumed_at' => $status === 'consumed' ? '2030-01-01 00:02:00' : null,
        'terminal_at' => $terminal ? '2030-01-01 00:02:00' : null,
        'created_at' => '2030-01-01 00:00:00',
        'updated_at' => $terminal ? '2030-01-01 00:02:00' : '2030-01-01 00:00:30',
    ]);
};
$persistence = static fn (string $code, ?DurableRetryScheduleSnapshot $snapshot = null): DurableRetryPersistenceResult =>
    new DurableRetryPersistenceResult($code, $snapshot);
$run = static function (
    int $previous,
    DeliveryCompletionResult $attemptResult,
    ?array $authority,
    string $closingStatus,
    string $closingReason,
    ?DurableRetryNextGenerationPersistenceResult $succession = null
) use ($schedule, $persistence): array {
    $scheduled = $schedule('scheduled', $previous);
    $claimed = DurableRetryScheduleSnapshot::fromArray(array_replace($scheduled->toArray(), [
        'status' => 'claimed', 'version' => 3,
        'claimed_at' => '2030-01-01 00:01:00', 'updated_at' => '2030-01-01 00:01:00',
    ]));
    $closed = DurableRetryScheduleSnapshot::fromArray(array_replace($claimed->toArray(), [
        'status' => $closingStatus, 'active_slot' => null, 'version' => 4,
        'reason_code' => $closingReason, 'terminal_at' => '2030-01-01 00:02:00',
        'updated_at' => '2030-01-01 00:02:00',
        'consumed_at' => $closingStatus === 'consumed' ? '2030-01-01 00:02:00' : null,
    ]));
    $attempts = new DeliveryIntegrationAttempts();
    $attempts->queue[] = $attemptResult;
    $reads = new DeliveryIntegrationReads();
    $reads->queue[] = $authority;
    $processor = new DurableRetryDeliveryCompletionProcessor($attempts, $reads);
    $repository = new DeliveryIntegrationRepository();
    $repository->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $scheduled);
    $repository->transitionQueue[] = $persistence(DurableRetryPersistenceResult::APPLIED, $claimed);
    if ($succession === null) {
        $repository->transitionQueue[] = $persistence(DurableRetryPersistenceResult::APPLIED, $closed);
    } else {
        $repository->successionQueue[] = $succession;
    }
    $coordinator = new DeliveryIntegrationCoordinator();
    if ($succession !== null) {
        $coordinator->queue[] = new DurableRetryCoordinationResult(
            DurableRetryCoordinationResult::SYNCHRONIZED_NEW, 71, 2, 901
        );
    }
    $times = ['2030-01-01 00:01:00', '2030-01-01 00:02:00'];
    $clock = static function () use (&$times): string { return array_shift($times); };
    $executor = new DurableRetryExecutor(
        $repository,
        new DurableRetryProcessingPolicy(),
        $coordinator,
        new class($processor) implements DurableRetryStageProcessorResolverInterface {
            public function __construct(private readonly DurableRetryStageProcessorInterface $processor) {}
            public function resolve(string $stage): DurableRetryStageProcessorInterface
            {
                if ($stage !== 'delivery_completion') { throw new LogicException('Unexpected stage.'); }
                return $this->processor;
            }
        },
        $clock(...)
    );
    $execution = $executor->execute('veciahorra_durable_retry_delivery_completion', 70, 1);
    return [$execution, $repository, $attempts, $reads, $coordinator, $executor];
};

[$success, $repository, $attempts, $reads, , $executor] = $run(
    0,
    $attempt(DeliveryCompletionResult::COMPLETED, 'deliveries_materialized'),
    $row('completed', 1, 'deliveries_materialized'),
    'consumed',
    'retry_consumed'
);
$assert($success->code() === DurableRetryExecutionResult::PROCESSED, 'success consumed');
$assert($repository->transitions[1][1]->status() === 'consumed', 'success transition');
$assert($attempts->calls === 1 && $reads->calls === 1, 'success call budget');
$repository->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $repository->transitions[1][1]);
$repeated = $executor->execute('veciahorra_durable_retry_delivery_completion', 70, 1);
$assert($repeated->code() === DurableRetryExecutionResult::ALREADY_COMPLETED, 'repeated callback rejected');
$assert($attempts->calls === 1 && $reads->calls === 1, 'repeated callback no functional call');
$repository->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $repository->transitions[1][1]);
$wrongHook = $executor->execute('veciahorra_durable_retry_business_completion', 70, 1);
$assert($wrongHook->code() === DurableRetryExecutionResult::HOOK_MISMATCH, 'wrong callback rejected');
$assert($attempts->calls === 1, 'wrong callback no functional call');

$successor = $schedule('dispatching', 1, 2, 71);
$succession = new DurableRetryNextGenerationPersistenceResult(
    DurableRetryNextGenerationPersistenceResult::CREATED,
    $schedule('superseded', 0),
    $successor
);
[$retry, $repository, $attempts, $reads, $coordinator] = $run(
    0,
    $attempt(DeliveryCompletionResult::RETRYABLE_FAILURE, 'unexpected_failure'),
    $row('retryable', 1, 'unexpected_failure'),
    'superseded',
    'superseded_generation',
    $succession
);
$assert($retry->code() === DurableRetryExecutionResult::RETRY_SCHEDULED, 'retry schedules next generation');
$assert(count($repository->successions) === 1, 'retry transactional succession');
$assert($coordinator->calls === [[71, 2]], 'coordinates successor only');
$assert($attempts->calls === 1 && $reads->calls === 1, 'retry call budget');

[$exhausted, $repository] = $run(
    4,
    $attempt(DeliveryCompletionResult::RETRYABLE_FAILURE, 'unexpected_failure'),
    $row('retryable', 5, 'unexpected_failure'),
    'failed',
    'processing_attempts_exhausted'
);
$assert($exhausted->code() === DurableRetryExecutionResult::ATTEMPTS_EXHAUSTED, 'fifth attempt exhausted');
$assert($repository->transitions[1][1]->toArray()['reason_code'] === 'processing_attempts_exhausted', 'exhaustion reason');

[$terminal, $repository, , , $coordinator] = $run(
    0,
    $attempt(DeliveryCompletionResult::PERMANENT_FAILURE, 'business_completion_not_completed'),
    $row('permanent_failure', 1, 'business_completion_not_completed'),
    'failed',
    'processing_terminal_failure'
);
$assert($terminal->code() === DurableRetryExecutionResult::TERMINAL_FAILURE, 'terminal rejection');
$assert($repository->transitions[1][1]->toArray()['reason_code'] === 'processing_terminal_failure', 'terminal reason');
$assert($coordinator->calls === [], 'terminal no coordination');

foreach ([
    [$row('processing', 1, null), 'known'],
    [null, 'nullable'],
] as [$authority, $label]) {
    [$uncertain, $repository, , , $coordinator] = $run(
        0,
        $attempt(DeliveryCompletionResult::RETRYABLE_FAILURE, 'lease_unavailable'),
        $authority,
        'orphaned',
        'processing_outcome_uncertain'
    );
    $assert($uncertain->code() === DurableRetryExecutionResult::OUTCOME_UNCERTAIN, "{$label} uncertainty");
    $assert($repository->transitions[1][1]->status() === 'orphaned', "{$label} orphaned");
    $assert($coordinator->calls === [], "{$label} no coordination");
}

[$contractError, $repository] = $run(
    0,
    $attempt(DeliveryCompletionResult::RETRYABLE_FAILURE, 'unexpected_failure'),
    $row('retryable', 2, 'unexpected_failure'),
    'orphaned',
    'processing_outcome_uncertain'
);
$assert($contractError->code() === DurableRetryExecutionResult::PROCESSING_CONTRACT_ERROR, 'counter contradiction');
$assert(count($repository->transitions) === 1, 'counter contradiction remains claimed');

echo "durable retry delivery completion executor integration: {$assertions} assertions\n";
