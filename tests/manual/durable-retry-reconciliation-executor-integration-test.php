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
use VeciAhorra\Modules\Orders\Services\DurableRetryExecutor;
use VeciAhorra\Modules\Orders\Services\DurableRetryReconciliationProcessor;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentReconciliationAttemptProcessorInterface;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentCompletionOutcomeInterface;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentReconciliationLeaseAuthorityInterface;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentReconciliationReadAuthorityInterface;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\LeaseAcquireResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\PaymentReconciliationProcessingResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationLease;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\TechnicalReconciliationResult;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;

final class ReconciliationIntegrationClaims implements PaymentReconciliationLeaseAuthorityInterface
{
    public array $queue = [];
    public int $calls = 0;
    public function newOwner(): string { return 'worker_' . str_repeat('a', 32); }
    public function acquireLease(int $reconciliationId, string $owner, mixed $durationSeconds = 600): LeaseAcquireResult
    {
        ++$this->calls;
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    }
}
final class ReconciliationIntegrationAttempts implements PaymentReconciliationAttemptProcessorInterface
{
    public array $queue = [];
    public int $calls = 0;
    public function process(ReconciliationLease $lease): PaymentReconciliationProcessingResult
    {
        ++$this->calls;
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    }
}
final class ReconciliationIntegrationReads implements PaymentReconciliationReadAuthorityInterface
{
    public array $queue = [];
    public int $calls = 0;
    public function find(int $id): ?PaymentReconciliation
    {
        ++$this->calls;
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    }
}
final class ReconciliationIntegrationRepository implements DurableRetryScheduleRepositoryInterface
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
final class ReconciliationIntegrationCoordinator implements DurableRetryExternalScheduleCoordinatorInterface
{
    public array $calls = [];
    public array $queue = [];
    public function coordinate(int $scheduleId, int $generation): DurableRetryCoordinationResult
    {
        $this->calls[] = func_get_args();
        return array_shift($this->queue);
    }
}
final class ReconciliationIntegrationRejectedOutcome implements PaymentCompletionOutcomeInterface
{
    public function successful(): bool { return false; }
    public function resultCode(): string { return 'rejected'; }
    public function targetReconciliationStatus(): string { return PaymentReconciliation::STATUS_PERMANENT_FAILURE; }
    public function lastErrorCode(): ?string { return 'functional_rejection'; }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) { throw new RuntimeException($message); }
};
$authority = static function (string $status, int $attempt, ?string $reason = null): PaymentReconciliation {
    $reflection = new ReflectionClass(PaymentReconciliation::class);
    $value = $reflection->newInstanceWithoutConstructor();
    foreach (['id' => 80, 'status' => $status, 'attemptCount' => $attempt, 'lastErrorCode' => $reason] as $name => $data) {
        $reflection->getProperty($name)->setValue($value, $data);
    }
    return $value;
};
$schedule = static function (string $status, int $attempt = 0, int $generation = 1, int $id = 70): DurableRetryScheduleSnapshot {
    $terminal = in_array($status, ['consumed', 'failed', 'orphaned', 'superseded'], true);
    return DurableRetryScheduleSnapshot::fromArray([
        'id' => $id,
        'public_id' => str_repeat($id === 70 ? 'a' : 'c', 64),
        'stage' => 'reconciliation',
        'subject_id' => 80,
        'completion_id' => 80,
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
$processing = static fn (string $status): PaymentReconciliationProcessingResult =>
    new PaymentReconciliationProcessingResult($status);
$run = static function (
    int $previousAttempt,
    LeaseAcquireResult|Throwable $claimResult,
    PaymentReconciliationProcessingResult|Throwable|null $attemptResult,
    PaymentReconciliation|Throwable|null $row,
    string $closingStatus,
    string $closingReason,
    ?DurableRetryNextGenerationPersistenceResult $succession = null
) use ($schedule, $persistence): array {
    $scheduled = $schedule('scheduled', $previousAttempt);
    $claimed = DurableRetryScheduleSnapshot::fromArray(array_replace($scheduled->toArray(), [
        'status' => 'claimed',
        'version' => 3,
        'claimed_at' => '2030-01-01 00:01:00',
        'updated_at' => '2030-01-01 00:01:00',
    ]));
    $closed = DurableRetryScheduleSnapshot::fromArray(array_replace($claimed->toArray(), [
        'status' => $closingStatus,
        'active_slot' => null,
        'version' => 4,
        'reason_code' => $closingReason,
        'terminal_at' => '2030-01-01 00:02:00',
        'updated_at' => '2030-01-01 00:02:00',
        'consumed_at' => $closingStatus === 'consumed' ? '2030-01-01 00:02:00' : null,
    ]));
    $claims = new ReconciliationIntegrationClaims();
    $claims->queue[] = $claimResult;
    $attempts = new ReconciliationIntegrationAttempts();
    if ($attemptResult !== null) { $attempts->queue[] = $attemptResult; }
    $reads = new ReconciliationIntegrationReads();
    $reads->queue[] = $row;
    $processor = new DurableRetryReconciliationProcessor($claims, $attempts, $reads);
    $repository = new ReconciliationIntegrationRepository();
    $repository->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $scheduled);
    $repository->transitionQueue[] = $persistence(DurableRetryPersistenceResult::APPLIED, $claimed);
    if ($succession === null) {
        $repository->transitionQueue[] = $persistence(DurableRetryPersistenceResult::APPLIED, $closed);
    } else {
        $repository->successionQueue[] = $succession;
    }
    $coordinator = new ReconciliationIntegrationCoordinator();
    if ($succession !== null) {
        $coordinator->queue[] = new DurableRetryCoordinationResult(
            DurableRetryCoordinationResult::SYNCHRONIZED_NEW,
            71,
            2,
            901
        );
    }
    $times = ['2030-01-01 00:01:00', '2030-01-01 00:02:00'];
    $clock = static function () use (&$times): string { return array_shift($times); };
    $resolver = new class($processor) implements DurableRetryStageProcessorResolverInterface {
        public function __construct(private readonly DurableRetryStageProcessorInterface $processor) {}
        public function resolve(string $stage): DurableRetryStageProcessorInterface
        {
            if ($stage !== 'reconciliation') { throw new LogicException('Unexpected stage.'); }
            return $this->processor;
        }
    };
    $executor = new DurableRetryExecutor($repository, new DurableRetryProcessingPolicy(), $coordinator, $resolver, $clock(...));
    return [$executor->execute('veciahorra_durable_retry_reconciliation', 70, 1), $repository, $claims, $attempts, $reads, $coordinator, $executor];
};
$lease = static fn (int $attempt): ReconciliationLease => new ReconciliationLease(
    80,
    'worker_' . str_repeat('a', 32),
    1,
    '2030-01-01 00:11:00',
    $attempt
);
$acquired = static fn (int $attempt): LeaseAcquireResult =>
    new LeaseAcquireResult(LeaseAcquireResult::ACQUIRED, $lease($attempt));

[$success, $repository, $claims, $attempts, $reads, $coordinator, $executor] = $run(
    0,
    $acquired(1),
    new PaymentReconciliationProcessingResult(
        PaymentReconciliationProcessingResult::PROCESSED,
        new TechnicalReconciliationResult(80, 'technical_completed', str_repeat('b', 64), str_repeat('c', 64))
    ),
    $authority(PaymentReconciliation::STATUS_COMPLETED, 1),
    'consumed',
    'retry_consumed'
);
$assert($success->code() === DurableRetryExecutionResult::PROCESSED, 'success consumed');
$assert($repository->transitions[1][1]->status() === 'consumed', 'success durable transition');
$assert($claims->calls === 1 && $attempts->calls === 1 && $reads->calls === 1, 'success call budget');
$assert($coordinator->calls === [], 'success no coordination');
$repository->readQueue[] = $persistence(
    DurableRetryPersistenceResult::EXISTING_COMPATIBLE,
    $repository->transitions[1][1]
);
$repeated = $executor->execute('veciahorra_durable_retry_reconciliation', 70, 1);
$assert($repeated->code() === DurableRetryExecutionResult::ALREADY_COMPLETED, 'repeated callback is idempotent');
$assert($claims->calls === 1 && $attempts->calls === 1, 'repeated callback does not invoke reconciliation');
$repository->readQueue[] = $persistence(
    DurableRetryPersistenceResult::EXISTING_COMPATIBLE,
    $repository->transitions[1][1]
);
$otherStage = $executor->execute(
    'veciahorra_durable_retry_business_completion',
    70,
    1
);
$assert($otherStage->code() === DurableRetryExecutionResult::HOOK_MISMATCH, 'other stage callback rejected');
$assert($claims->calls === 1 && $attempts->calls === 1, 'other stage never invokes reconciliation');

$successor = $schedule('dispatching', 1, 2, 71);
$superseded = $schedule('superseded', 0);
$succession = new DurableRetryNextGenerationPersistenceResult(
    DurableRetryNextGenerationPersistenceResult::CREATED,
    $superseded,
    $successor
);
[$retry, $repository, $claims, $attempts, $reads, $coordinator] = $run(
    0,
    $acquired(1),
    $processing(PaymentReconciliationProcessingResult::RECOVERABLE_ERROR),
    $authority(PaymentReconciliation::STATUS_RETRYABLE, 1, 'technical_internal_error'),
    'superseded',
    'superseded_generation',
    $succession
);
$assert($retry->code() === DurableRetryExecutionResult::RETRY_SCHEDULED, 'retry creates successor');
$assert(count($repository->successions) === 1, 'retry uses transactional succession');
$assert($coordinator->calls === [[71, 2]], 'coordinator receives successor only');
$assert($claims->calls === 1 && $attempts->calls === 1 && $reads->calls === 1, 'retry call budget');

[$exhausted, $repository] = $run(
    4,
    $acquired(5),
    $processing(PaymentReconciliationProcessingResult::RECOVERABLE_ERROR),
    $authority(PaymentReconciliation::STATUS_RETRYABLE, 5, 'technical_internal_error'),
    'failed',
    'processing_attempts_exhausted'
);
$assert($exhausted->code() === DurableRetryExecutionResult::ATTEMPTS_EXHAUSTED, 'fifth attempt exhausted');
$assert($repository->transitions[1][1]->toArray()['reason_code'] === 'processing_attempts_exhausted', 'exhaustion reason');

[$terminal, $repository, $claims, $attempts, $reads, $coordinator] = $run(
    0,
    $acquired(1),
    new PaymentReconciliationProcessingResult(
        PaymentReconciliationProcessingResult::COMPLETION_REJECTED,
        new TechnicalReconciliationResult(80, 'technical_rejected', str_repeat('b', 64), str_repeat('c', 64)),
        false,
        new ReconciliationIntegrationRejectedOutcome()
    ),
    $authority(PaymentReconciliation::STATUS_PERMANENT_FAILURE, 1, 'functional_rejection'),
    'failed',
    'processing_terminal_failure'
);
$assert($terminal->code() === DurableRetryExecutionResult::TERMINAL_FAILURE, 'terminal result closes failed');
$assert($repository->transitions[1][1]->toArray()['reason_code'] === 'processing_terminal_failure', 'terminal reason');
$assert($repository->successions === [] && $coordinator->calls === [], 'terminal no successor or coordination');

foreach ([
    [$acquired(1), $processing(PaymentReconciliationProcessingResult::HEARTBEAT_REJECTED), $authority(PaymentReconciliation::STATUS_PROCESSING, 1), 'known'],
    [new LeaseAcquireResult(LeaseAcquireResult::NOT_FOUND), null, null, 'unknown'],
] as [$claimResult, $attemptResult, $row, $label]) {
    [$uncertain, $repository, $claims, $attempts, $reads, $coordinator] = $run(
        0,
        $claimResult,
        $attemptResult,
        $row,
        'orphaned',
        'processing_outcome_uncertain'
    );
    $assert($uncertain->code() === DurableRetryExecutionResult::OUTCOME_UNCERTAIN, "{$label} uncertainty orphaned");
    $assert($uncertain->interventionRequired(), "{$label} uncertainty intervention");
    $assert($repository->transitions[1][1]->status() === 'orphaned', "{$label} orphaned transition");
    $assert($repository->successions === [] && $coordinator->calls === [], "{$label} no successor or coordination");
}

[$contractError, $repository, $claims, $attempts] = $run(
    0,
    $acquired(2),
    null,
    null,
    'orphaned',
    'processing_outcome_uncertain'
);
$assert($contractError->code() === DurableRetryExecutionResult::PROCESSING_CONTRACT_ERROR, 'contradictory counter contract error');
$assert(count($repository->transitions) === 1, 'contract error leaves claimed');
$assert($attempts->calls === 0, 'contradictory lease does not process');

[$exception, $repository, $claims, $attempts] = $run(
    0,
    $acquired(1),
    new RuntimeException('secret token'),
    $authority(PaymentReconciliation::STATUS_PROCESSING, 1),
    'orphaned',
    'processing_outcome_uncertain'
);
$assert($exception->code() === DurableRetryExecutionResult::OUTCOME_UNCERTAIN, 'functional exception orphaned');
$assert($attempts->calls === 1, 'functional exception no retry loop');
$assert(! method_exists($exception, 'exception') && ! method_exists($exception, 'message'), 'functional exception hidden');

echo "durable retry reconciliation executor integration: {$assertions} assertions\n";
