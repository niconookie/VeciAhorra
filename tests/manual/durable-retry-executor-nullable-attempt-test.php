<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryScheduleRepositoryInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryStageProcessorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryStageProcessorResolverInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryCoordinationResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionContext;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextAttemptDecision;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextGenerationPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingPolicy;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Orders\Services\DurableRetryExecutor;

final class NullableAttemptRepositoryDouble implements DurableRetryScheduleRepositoryInterface
{
    public array $reads = [];
    public array $transitions = [];
    public array $successions = [];
    public array $readQueue = [];
    public array $transitionQueue = [];

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
        throw new LogicException('Nullable uncertainty must not create a successor.');
    }
}

final class NullableAttemptProcessorDouble implements DurableRetryStageProcessorInterface
{
    public array $contexts = [];
    public mixed $next;

    public function stage(): string { return 'business_completion'; }
    public function process(DurableRetryExecutionContext $context): DurableRetryProcessingResult
    {
        $this->contexts[] = $context;
        if ($this->next instanceof Throwable) {
            throw $this->next;
        }
        return $this->next;
    }
}

final class NullableAttemptCoordinatorDouble implements DurableRetryExternalScheduleCoordinatorInterface
{
    public array $calls = [];
    public function coordinate(int $scheduleId, int $generation): DurableRetryCoordinationResult
    {
        $this->calls[] = func_get_args();
        throw new LogicException('Nullable uncertainty must not coordinate.');
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$snapshot = static function (string $status, int $version): DurableRetryScheduleSnapshot {
    $terminal = $status === 'orphaned';
    return DurableRetryScheduleSnapshot::fromArray([
        'id' => 70,
        'public_id' => str_repeat('a', 64),
        'stage' => 'business_completion',
        'subject_id' => 800,
        'completion_id' => 700,
        'generation' => 1,
        'attempt_number' => 2,
        'scheduled_for' => '2030-01-01 00:01:00',
        'scheduled_action_id' => 900,
        'dispatch_token_hash' => str_repeat('b', 64),
        'status' => $status,
        'active_slot' => $terminal ? null : 1,
        'version' => $version,
        'reason_code' => $terminal ? 'processing_outcome_uncertain' : 'retryable_failure',
        'dispatched_at' => '2030-01-01 00:00:30',
        'claimed_at' => $status === 'scheduled' ? null : '2030-01-01 00:01:00',
        'consumed_at' => null,
        'terminal_at' => $terminal ? '2030-01-01 00:02:00' : null,
        'created_at' => '2030-01-01 00:00:00',
        'updated_at' => $terminal ? '2030-01-01 00:02:00' : ($status === 'claimed' ? '2030-01-01 00:01:00' : '2030-01-01 00:00:30'),
    ]);
};
$persistence = static fn (
    string $code,
    ?DurableRetryScheduleSnapshot $value = null
): DurableRetryPersistenceResult => new DurableRetryPersistenceResult($code, $value);
$execute = static function (
    mixed $processing,
    bool $closeApplied = true,
    bool $rereadClaimed = false
) use ($snapshot, $persistence): array {
    $scheduled = $snapshot('scheduled', 2);
    $claimed = $snapshot('claimed', 3);
    $orphaned = $snapshot('orphaned', 4);
    $repository = new NullableAttemptRepositoryDouble();
    $repository->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $scheduled);
    $repository->transitionQueue[] = $persistence(DurableRetryPersistenceResult::APPLIED, $claimed);
    if ($closeApplied) {
        $repository->transitionQueue[] = $persistence(DurableRetryPersistenceResult::APPLIED, $orphaned);
    } elseif ($rereadClaimed) {
        $repository->transitionQueue[] = $persistence(DurableRetryPersistenceResult::CONFLICT);
        $repository->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $claimed);
    }
    $processor = new NullableAttemptProcessorDouble();
    $processor->next = $processing;
    $coordinator = new NullableAttemptCoordinatorDouble();
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

    return [
        $executor->execute('veciahorra_durable_retry_business_completion', 70, 1),
        $repository,
        $processor,
        $coordinator,
    ];
};

foreach ([
    ['without', DurableRetryProcessingResult::outcomeUncertain()],
    ['with', DurableRetryProcessingResult::outcomeUncertain(3)],
] as [$label, $processing]) {
    [$result, $repository, $processor, $coordinator] = $execute($processing);
    $assert($result->code() === DurableRetryExecutionResult::OUTCOME_UNCERTAIN, "{$label} attempt closes uncertain");
    $assert($result->succeeded(), "{$label} attempt persistence confirmed");
    $assert($result->processorInvoked(), "{$label} attempt processor invoked");
    $assert($result->interventionRequired(), "{$label} attempt requires intervention");
    $assert(count($processor->contexts) === 1, "{$label} attempt processes once");
    $assert(count($repository->transitions) === 2, "{$label} attempt claims and closes");
    $assert($repository->transitions[1][1]->status() === 'orphaned', "{$label} attempt closes orphaned");
    $assert($repository->transitions[1][1]->toArray()['reason_code'] === 'processing_outcome_uncertain', "{$label} attempt reason");
    $assert($repository->successions === [], "{$label} attempt creates no successor");
    $assert($coordinator->calls === [], "{$label} attempt never coordinates");
}

[$contradiction, $repository, $processor, $coordinator] = $execute(
    DurableRetryProcessingResult::outcomeUncertain(4)
);
$assert($contradiction->code() === DurableRetryExecutionResult::PROCESSING_CONTRACT_ERROR, 'contradictory attempt is contract error');
$assert(! $contradiction->succeeded(), 'contract error is closed failure');
$assert($contradiction->processorInvoked(), 'contract error records processor invocation');
$assert($contradiction->interventionRequired(), 'contract error requires intervention');
$assert(count($repository->transitions) === 1, 'contract error does not close claimed row');
$assert($repository->successions === [], 'contract error creates no successor');
$assert($coordinator->calls === [], 'contract error never coordinates');

[$exception, $repository, $processor, $coordinator] = $execute(
    new RuntimeException('secret SQL path')
);
$assert($exception->code() === DurableRetryExecutionResult::OUTCOME_UNCERTAIN, 'processor exception becomes uncertain');
$assert($exception->succeeded(), 'exception closure persisted');
$assert($exception->interventionRequired(), 'exception requires intervention');
$assert(count($processor->contexts) === 1, 'throwing processor invoked once');
$assert($repository->transitions[1][1]->status() === 'orphaned', 'exception closes orphaned');
$assert($repository->successions === [], 'exception creates no successor');
$assert($coordinator->calls === [], 'exception never coordinates');
$assert(! method_exists($exception, 'message'), 'exception message not exposed');
$assert(! method_exists($exception, 'exception'), 'exception object not exposed');

[$persistenceFailure, $repository, $processor, $coordinator] = $execute(
    DurableRetryProcessingResult::outcomeUncertain(),
    false,
    true
);
$assert($persistenceFailure->code() === DurableRetryExecutionResult::DURABLE_INCONSISTENCY, 'failed orphan closure is durable inconsistency');
$assert($persistenceFailure->interventionRequired(), 'failed orphan closure requires intervention');
$assert($persistenceFailure->processorInvoked(), 'failed orphan closure records processing');
$assert(count($repository->reads) === 2, 'failed closure rereads durable state');
$assert(count($repository->transitions) === 2, 'failed closure attempts exact transition');
$assert($repository->transitions[1][1]->status() === 'orphaned', 'failed closure target remains orphaned');
$assert($repository->successions === [], 'failed closure creates no successor');
$assert($coordinator->calls === [], 'failed closure never coordinates');

echo "durable retry executor nullable attempt: {$assertions} assertions\n";
