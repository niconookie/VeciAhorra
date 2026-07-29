<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

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
use VeciAhorra\Modules\Orders\Services\DurableRetryBusinessCompletionProcessor;
use VeciAhorra\Modules\Orders\Services\DurableRetryExecutor;
use VeciAhorra\Modules\Payments\BusinessCompletion\Contracts\BusinessCompletionAttemptProcessorInterface;
use VeciAhorra\Modules\Payments\BusinessCompletion\Contracts\BusinessCompletionReadAuthorityInterface;
use VeciAhorra\Modules\Payments\BusinessCompletion\DTO\BusinessCompletionResult;

final class BusinessIntegrationAttempts implements BusinessCompletionAttemptProcessorInterface
{
    public array $queue = [];
    public int $calls = 0;
    public function process(int $reconciliationId, string $workerId, int $leaseSeconds = 30): BusinessCompletionResult
    {
        ++$this->calls;
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    }
}
final class BusinessIntegrationReads implements BusinessCompletionReadAuthorityInterface
{
    public array $queue = [];
    public int $calls = 0;
    public function findByReconciliation(int $reconciliationId): ?array
    {
        ++$this->calls;
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    }
}
final class BusinessIntegrationRepository implements DurableRetryScheduleRepositoryInterface
{
    public array $reads = [];
    public array $transitions = [];
    public array $successions = [];
    public array $readQueue = [];
    public array $transitionQueue = [];
    public array $successionQueue = [];
    public function create(array $initialFields): DurableRetryPersistenceResult { throw new LogicException(); }
    public function findById(int $id): DurableRetryPersistenceResult
    {
        $this->reads[] = $id;
        return array_shift($this->readQueue);
    }
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
final class BusinessIntegrationCoordinator implements DurableRetryExternalScheduleCoordinatorInterface
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
    'reconciliation_id' => 80,
    'status' => $status,
    'attempt_count' => $attempt,
    'last_result_code' => $reason,
    'payment_id' => $status === 'completed' ? 100 : null,
    'fulfillment_method' => $status === 'completed' ? 'delivery' : null,
    'completed_at' => $status === 'completed' ? '2030-01-01 00:02:00' : null,
];
$attempt = static fn (string $status, string $reason): BusinessCompletionResult =>
    new BusinessCompletionResult($status, $reason, 80, 90, $status === 'completed' ? 100 : null);
$schedule = static function (string $status, int $attempt = 0, int $generation = 1, int $id = 70): DurableRetryScheduleSnapshot {
    $terminal = in_array($status, ['consumed', 'failed', 'orphaned', 'superseded'], true);
    return DurableRetryScheduleSnapshot::fromArray([
        'id' => $id,
        'public_id' => str_repeat($id === 70 ? 'a' : 'c', 64),
        'stage' => 'business_completion',
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
    BusinessCompletionResult|Throwable $attemptResult,
    array|Throwable|null $authority,
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
    $attempts = new BusinessIntegrationAttempts();
    $attempts->queue[] = $attemptResult;
    $reads = new BusinessIntegrationReads();
    $reads->queue[] = $authority;
    $processor = new DurableRetryBusinessCompletionProcessor($attempts, $reads);
    $repository = new BusinessIntegrationRepository();
    $repository->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $scheduled);
    $repository->transitionQueue[] = $persistence(DurableRetryPersistenceResult::APPLIED, $claimed);
    if ($succession === null) {
        $repository->transitionQueue[] = $persistence(DurableRetryPersistenceResult::APPLIED, $closed);
    } else {
        $repository->successionQueue[] = $succession;
    }
    $coordinator = new BusinessIntegrationCoordinator();
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
                if ($stage !== 'business_completion') { throw new LogicException('Unexpected stage.'); }
                return $this->processor;
            }
        },
        $clock(...)
    );
    $execution = $executor->execute('veciahorra_durable_retry_business_completion', 70, 1);
    return [$execution, $repository, $attempts, $reads, $coordinator, $executor];
};

[$success, $repository, $attempts, $reads, $coordinator, $executor] = $run(
    0,
    $attempt(BusinessCompletionResult::COMPLETED, 'completed'),
    $row('completed', 1, 'completed'),
    'consumed',
    'retry_consumed'
);
$assert($success->code() === DurableRetryExecutionResult::PROCESSED, 'success consumed');
$assert($repository->transitions[1][1]->status() === 'consumed', 'success transition');
$assert($attempts->calls === 1 && $reads->calls === 1, 'success call budget');
$repository->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $repository->transitions[1][1]);
$repeated = $executor->execute('veciahorra_durable_retry_business_completion', 70, 1);
$assert($repeated->code() === DurableRetryExecutionResult::ALREADY_COMPLETED, 'repeated callback rejected');
$assert($attempts->calls === 1 && $reads->calls === 1, 'repeated callback zero business calls');
$repository->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $repository->transitions[1][1]);
$wrongHook = $executor->execute('veciahorra_durable_retry_reconciliation', 70, 1);
$assert($wrongHook->code() === DurableRetryExecutionResult::HOOK_MISMATCH, 'wrong stage callback rejected');
$assert($attempts->calls === 1, 'wrong stage zero business calls');

$successor = $schedule('dispatching', 1, 2, 71);
$succession = new DurableRetryNextGenerationPersistenceResult(
    DurableRetryNextGenerationPersistenceResult::CREATED,
    $schedule('superseded', 0),
    $successor
);
[$retry, $repository, $attempts, $reads, $coordinator] = $run(
    0,
    $attempt(BusinessCompletionResult::RETRYABLE, 'unexpected_failure'),
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
    $attempt(BusinessCompletionResult::RETRYABLE, 'unexpected_failure'),
    $row('retryable', 5, 'unexpected_failure'),
    'failed',
    'processing_attempts_exhausted'
);
$assert($exhausted->code() === DurableRetryExecutionResult::ATTEMPTS_EXHAUSTED, 'fifth attempt exhausted');
$assert($repository->transitions[1][1]->toArray()['reason_code'] === 'processing_attempts_exhausted', 'exhaustion reason');

[$terminal, $repository, , , $coordinator] = $run(
    0,
    $attempt(BusinessCompletionResult::PERMANENT_FAILURE, 'unsupported_origin'),
    $row('permanent_failure', 1, 'unsupported_origin'),
    'failed',
    'processing_terminal_failure'
);
$assert($terminal->code() === DurableRetryExecutionResult::TERMINAL_FAILURE, 'functional rejection terminal');
$assert($repository->transitions[1][1]->toArray()['reason_code'] === 'processing_terminal_failure', 'terminal reason');
$assert($repository->successions === [] && $coordinator->calls === [], 'terminal no coordination');

foreach ([
    [$row('processing', 1, null), 'known'],
    [null, 'unknown'],
] as [$authority, $label]) {
    [$uncertain, $repository, , , $coordinator] = $run(
        0,
        $attempt(BusinessCompletionResult::RETRYABLE, 'claim_unavailable'),
        $authority,
        'orphaned',
        'processing_outcome_uncertain'
    );
    $assert($uncertain->code() === DurableRetryExecutionResult::OUTCOME_UNCERTAIN, "{$label} uncertainty orphaned");
    $assert($repository->transitions[1][1]->status() === 'orphaned', "{$label} orphaned");
    $assert($coordinator->calls === [], "{$label} no coordination");
}

[$contractError, $repository] = $run(
    0,
    $attempt(BusinessCompletionResult::RETRYABLE, 'unexpected_failure'),
    $row('retryable', 2, 'unexpected_failure'),
    'orphaned',
    'processing_outcome_uncertain'
);
$assert($contractError->code() === DurableRetryExecutionResult::PROCESSING_CONTRACT_ERROR, 'contradictory counter contract error');
$assert(count($repository->transitions) === 1, 'contract error remains claimed');
$serialized = serialize([$contractError, $terminal]);
$assert(! str_contains($serialized, 'SQL') && ! str_contains($serialized, 'token'), 'closed results expose no sensitive data');

echo "durable retry business completion executor integration: {$assertions} assertions\n";
