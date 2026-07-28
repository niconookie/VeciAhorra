<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryScheduleRepositoryInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryStageProcessorInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryCoordinationResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionContext;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextAttemptDecision;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextGenerationPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingFailure;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingPolicy;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Orders\Services\DurableRetryExecutor;

final class ExecutorRepositoryDouble implements DurableRetryScheduleRepositoryInterface
{
    public array $reads = [];
    public array $transitions = [];
    public array $successions = [];
    public array $readQueue = [];
    public array $transitionQueue = [];
    public array $successionQueue = [];
    public array $operations = [];

    public function create(array $initialFields): DurableRetryPersistenceResult { throw new LogicException(); }
    public function findById(int $id): DurableRetryPersistenceResult
    {
        $this->reads[] = $id;
        $this->operations[] = 'read';
        $next = array_shift($this->readQueue);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    }
    public function findByIdentity(string $stage, int $subjectId, int $generation): DurableRetryPersistenceResult { throw new LogicException(); }
    public function associateScheduledAction(int $id, int $expectedVersion, int $scheduledActionId, string $dispatchedAt, string $updatedAt): DurableRetryPersistenceResult { throw new LogicException(); }
    public function transition(DurableRetryScheduleSnapshot $expected, DurableRetryScheduleSnapshot $target): DurableRetryPersistenceResult
    {
        $this->transitions[] = [$expected, $target];
        $this->operations[] = 'transition:' . $target->status();
        $next = array_shift($this->transitionQueue);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    }
    public function supersedeAndCreateNextGeneration(DurableRetryScheduleSnapshot $claimed, DurableRetryNextAttemptDecision $decision, string $supersededAtUtc): DurableRetryNextGenerationPersistenceResult
    {
        $this->successions[] = func_get_args();
        $this->operations[] = 'succession';
        $next = array_shift($this->successionQueue);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    }
}

final class ExecutorProcessorDouble implements DurableRetryStageProcessorInterface
{
    public array $contexts = [];
    public mixed $next;
    public function __construct(private readonly string $processorStage) {}
    public function stage(): string { return $this->processorStage; }
    public function process(DurableRetryExecutionContext $context): DurableRetryProcessingResult
    {
        $this->contexts[] = $context;
        if ($this->next instanceof Throwable) { throw $this->next; }
        return $this->next;
    }
}

final class ExecutorCoordinatorDouble implements DurableRetryExternalScheduleCoordinatorInterface
{
    public array $calls = [];
    public mixed $next;
    public function coordinate(int $scheduleId, int $generation): DurableRetryCoordinationResult
    {
        $this->calls[] = func_get_args();
        if ($this->next instanceof Throwable) { throw $this->next; }
        return $this->next;
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) { throw new RuntimeException($message); }
};
$fields = static function (
    string $status = 'scheduled',
    int $generation = 1,
    int $attempt = 0,
    int $id = 70
): array {
    $inactive = in_array($status, ['consumed', 'failed', 'orphaned', 'superseded'], true);
    return [
        'id' => $id,
        'public_id' => str_repeat('a', 64),
        'stage' => 'business_completion',
        'subject_id' => 800,
        'completion_id' => 700,
        'generation' => $generation,
        'attempt_number' => $attempt,
        'scheduled_for' => '2030-01-01 00:01:00',
        'scheduled_action_id' => $status === 'dispatching' ? null : 900,
        'dispatch_token_hash' => str_repeat('b', 64),
        'status' => $status,
        'active_slot' => $inactive ? null : 1,
        'version' => $status === 'scheduled' ? 2 : 3,
        'reason_code' => match ($status) {
            'consumed' => 'retry_consumed',
            'failed' => 'processing_terminal_failure',
            'orphaned' => 'processing_outcome_uncertain',
            'superseded' => 'superseded_generation',
            default => 'retryable_failure',
        },
        'dispatched_at' => $status === 'dispatching' ? null : '2030-01-01 00:00:30',
        'claimed_at' => in_array($status, ['claimed', 'consumed', 'failed', 'orphaned', 'superseded'], true) ? '2030-01-01 00:01:00' : null,
        'consumed_at' => $status === 'consumed' ? '2030-01-01 00:03:00' : null,
        'terminal_at' => $inactive ? '2030-01-01 00:03:00' : null,
        'created_at' => '2030-01-01 00:00:00',
        'updated_at' => $inactive ? '2030-01-01 00:03:00' : ($status === 'claimed' ? '2030-01-01 00:01:00' : '2030-01-01 00:00:30'),
    ];
};
$snapshot = static fn (array $value): DurableRetryScheduleSnapshot => DurableRetryScheduleSnapshot::fromArray($value);
$persistence = static fn (string $code, ?DurableRetryScheduleSnapshot $value = null): DurableRetryPersistenceResult => new DurableRetryPersistenceResult($code, $value);
$claimed = static function (DurableRetryScheduleSnapshot $scheduled): DurableRetryScheduleSnapshot {
    return DurableRetryScheduleSnapshot::fromArray(array_replace($scheduled->toArray(), [
        'status' => 'claimed', 'version' => $scheduled->version() + 1,
        'claimed_at' => '2030-01-01 00:01:00', 'updated_at' => '2030-01-01 00:01:00',
    ]));
};
$closed = static function (DurableRetryScheduleSnapshot $claim, string $status, string $reason): DurableRetryScheduleSnapshot {
    $changes = [
        'status' => $status, 'active_slot' => null, 'version' => $claim->version() + 1,
        'reason_code' => $reason, 'terminal_at' => '2030-01-01 00:02:00',
        'updated_at' => '2030-01-01 00:02:00',
    ];
    if ($status === 'consumed') { $changes['consumed_at'] = '2030-01-01 00:02:00'; }
    return DurableRetryScheduleSnapshot::fromArray(array_replace($claim->toArray(), $changes));
};
$make = static function (
    ExecutorRepositoryDouble $repo,
    ExecutorProcessorDouble $processor,
    ExecutorCoordinatorDouble $coordinator,
    array $times = ['2030-01-01 00:01:00', '2030-01-01 00:02:00']
): DurableRetryExecutor {
    $clock = static function () use (&$times): string { return array_shift($times); };
    return new DurableRetryExecutor($repo, new DurableRetryProcessingPolicy(), $coordinator, $processor, $clock(...));
};
$hook = 'veciahorra_durable_retry_business_completion';

foreach ([['', 70, 1], ['unknown', 70, 1], [$hook, 0, 1], [$hook, 70, 0]] as $case) {
    $repo = new ExecutorRepositoryDouble();
    $processor = new ExecutorProcessorDouble('business_completion');
    $coordinator = new ExecutorCoordinatorDouble();
    $result = $make($repo, $processor, $coordinator)->execute(...$case);
    $assert($result->code() === DurableRetryExecutionResult::INVALID_INVOCATION, 'invalid invocation');
    $assert($repo->reads === [] && $processor->contexts === [] && $coordinator->calls === [], 'invalid invocation has zero effects');
}

$repo = new ExecutorRepositoryDouble();
$repo->readQueue[] = $persistence(DurableRetryPersistenceResult::NOT_FOUND);
$processor = new ExecutorProcessorDouble('business_completion');
$coordinator = new ExecutorCoordinatorDouble();
$assert($make($repo, $processor, $coordinator)->execute($hook, 70, 1)->code() === DurableRetryExecutionResult::NOT_FOUND, 'not found');
$assert(count($repo->reads) === 1, 'not found one read');

foreach ([
    ['claimed', DurableRetryExecutionResult::ALREADY_CLAIMED],
    ['consumed', DurableRetryExecutionResult::ALREADY_COMPLETED],
    ['failed', DurableRetryExecutionResult::ALREADY_TERMINAL],
    ['orphaned', DurableRetryExecutionResult::ALREADY_TERMINAL],
    ['superseded', DurableRetryExecutionResult::ALREADY_TERMINAL],
    ['dispatching', DurableRetryExecutionResult::INELIGIBLE_STATE],
] as [$status, $expected]) {
    $repo = new ExecutorRepositoryDouble();
    $repo->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $snapshot($fields($status)));
    $processor = new ExecutorProcessorDouble('business_completion');
    $coordinator = new ExecutorCoordinatorDouble();
    $result = $make($repo, $processor, $coordinator)->execute($hook, 70, 1);
    $assert($result->code() === $expected, "state {$status}");
    $assert($processor->contexts === [], "state {$status} not processed");
}

$scheduled = $snapshot($fields());
$claim = $claimed($scheduled);
$consumed = $closed($claim, 'consumed', 'retry_consumed');
$repo = new ExecutorRepositoryDouble();
$repo->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $scheduled);
$repo->transitionQueue[] = $persistence(DurableRetryPersistenceResult::APPLIED, $claim);
$repo->transitionQueue[] = $persistence(DurableRetryPersistenceResult::APPLIED, $consumed);
$processor = new ExecutorProcessorDouble('business_completion');
$processor->next = DurableRetryProcessingResult::succeeded(1);
$coordinator = new ExecutorCoordinatorDouble();
$result = $make($repo, $processor, $coordinator)->execute($hook, 70, 1);
$assert($result->code() === DurableRetryExecutionResult::PROCESSED && $result->succeeded(), 'success processed');
$assert($result->processorInvoked(), 'success processor flag');
$assert(count($processor->contexts) === 1, 'processor exactly once');
$context = $processor->contexts[0];
$assert([$context->scheduleId(), $context->stage(), $context->subjectId(), $context->completionId()] === [70, 'business_completion', 800, 700], 'minimal context identity');
$assert([$context->generation(), $context->previousAttemptNumber(), $context->expectedAttemptNumber()] === [1, 0, 1], 'minimal context attempt');
$assert($context->claimedAtUtc() === '2030-01-01 00:01:00', 'claim clock explicit');
$assert(array_column($repo->operations, null) === $repo->operations, 'operation trace available');
$assert($repo->operations === ['read', 'transition:claimed', 'transition:consumed'], 'claim before process closure');
$assert($coordinator->calls === [], 'success never coordinates');

$repo = new ExecutorRepositoryDouble();
$repo->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $scheduled);
$repo->transitionQueue[] = $persistence(DurableRetryPersistenceResult::CONFLICT);
$repo->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $claim);
$processor = new ExecutorProcessorDouble('business_completion');
$processor->next = DurableRetryProcessingResult::succeeded(1);
$coordinator = new ExecutorCoordinatorDouble();
$lost = $make($repo, $processor, $coordinator)->execute($hook, 70, 1);
$assert($lost->code() === DurableRetryExecutionResult::ALREADY_CLAIMED, 'claim loser classified');
$assert(count($repo->reads) === 2 && count($repo->transitions) === 1, 'claim loser budget');
$assert($processor->contexts === [], 'claim loser never processes');

foreach ([
    [DurableRetryProcessingFailure::TERMINAL_FAILURE, DurableRetryProcessingFailure::CONFIRMED_TERMINAL_FAILURE, 'failed', 'processing_terminal_failure', DurableRetryExecutionResult::TERMINAL_FAILURE],
    [DurableRetryProcessingFailure::OUTCOME_UNCERTAIN, DurableRetryProcessingFailure::TECHNICAL_OUTCOME_UNCERTAIN, 'orphaned', 'processing_outcome_uncertain', DurableRetryExecutionResult::OUTCOME_UNCERTAIN],
] as [$classification, $failureCode, $status, $reason, $resultCode]) {
    $claim = $claimed($scheduled);
    $terminal = $closed($claim, $status, $reason);
    $repo = new ExecutorRepositoryDouble();
    $repo->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $scheduled);
    $repo->transitionQueue[] = $persistence(DurableRetryPersistenceResult::APPLIED, $claim);
    $repo->transitionQueue[] = $persistence(DurableRetryPersistenceResult::APPLIED, $terminal);
    $processor = new ExecutorProcessorDouble('business_completion');
    $processor->next = DurableRetryProcessingResult::failed(new DurableRetryProcessingFailure($classification, $failureCode, 1));
    $coordinator = new ExecutorCoordinatorDouble();
    $result = $make($repo, $processor, $coordinator)->execute($hook, 70, 1);
    $assert($result->code() === $resultCode && $result->succeeded(), "{$status} closure");
    $assert($repo->transitions[1][1]->toArray()['reason_code'] === $reason, "{$status} reason");
    $assert($repo->successions === [] && $coordinator->calls === [], "{$status} no retry");
}

$claim = $claimed($scheduled);
$superseded = $closed($claim, 'superseded', 'superseded_generation');
$successorFields = $fields('dispatching', 2, 1, 71);
$successorFields['public_id'] = str_repeat('c', 64);
$successorFields['dispatch_token_hash'] = str_repeat('d', 64);
$successorFields['scheduled_for'] = '2030-01-01 00:03:00';
$successorFields['completion_id'] = 700;
$successorFields['created_at'] = '2030-01-01 00:02:00';
$successorFields['updated_at'] = '2030-01-01 00:02:00';
$successor = $snapshot($successorFields);
$repo = new ExecutorRepositoryDouble();
$repo->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $scheduled);
$repo->transitionQueue[] = $persistence(DurableRetryPersistenceResult::APPLIED, $claim);
$repo->successionQueue[] = new DurableRetryNextGenerationPersistenceResult(DurableRetryNextGenerationPersistenceResult::CREATED, $superseded, $successor);
$processor = new ExecutorProcessorDouble('business_completion');
$processor->next = DurableRetryProcessingResult::failed(new DurableRetryProcessingFailure(DurableRetryProcessingFailure::RETRYABLE_FAILURE, DurableRetryProcessingFailure::CONFIRMED_RETRYABLE_FAILURE, 1));
$coordinator = new ExecutorCoordinatorDouble();
$coordinator->next = new DurableRetryCoordinationResult(DurableRetryCoordinationResult::SYNCHRONIZED_NEW, 71, 2, 901);
$retry = $make($repo, $processor, $coordinator)->execute($hook, 70, 1);
$assert($retry->code() === DurableRetryExecutionResult::RETRY_SCHEDULED && $retry->succeeded(), 'retry scheduled');
$assert([$retry->nextScheduleId(), $retry->nextGeneration()] === [71, 2], 'retry exposes successor identity');
$assert($repo->successions[0][0]->id() === 70, 'succession historical identity');
$assert($repo->successions[0][1]->nextAttemptNumber() === 1, 'confirmed attempt persisted');
$assert($coordinator->calls === [[71, 2]], 'coordinates only successor');
$assert($retry->retryPrepared() && $retry->externallyCoordinated(), 'retry flags');

$repo = new ExecutorRepositoryDouble();
$repo->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $scheduled);
$repo->transitionQueue[] = $persistence(DurableRetryPersistenceResult::APPLIED, $claim);
$repo->transitionQueue[] = $persistence(DurableRetryPersistenceResult::APPLIED, $closed($claim, 'orphaned', 'processing_outcome_uncertain'));
$processor = new ExecutorProcessorDouble('business_completion');
$processor->next = new RuntimeException('secret path and SQL');
$coordinator = new ExecutorCoordinatorDouble();
$uncertain = $make($repo, $processor, $coordinator)->execute($hook, 70, 1);
$assert($uncertain->code() === DurableRetryExecutionResult::OUTCOME_UNCERTAIN, 'exception becomes uncertain');
$assert(count($processor->contexts) === 1, 'throwing processor called once');
$assert(! method_exists($uncertain, 'message') && ! method_exists($uncertain, 'exception'), 'exception details not exposed');

$repo = new ExecutorRepositoryDouble();
$repo->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $scheduled);
$processor = new ExecutorProcessorDouble('delivery_completion');
$coordinator = new ExecutorCoordinatorDouble();
$assert($make($repo, $processor, $coordinator)->execute($hook, 70, 1)->code() === DurableRetryExecutionResult::PROCESSOR_MISMATCH, 'processor mismatch');
$assert($processor->contexts === [] && $repo->transitions === [], 'processor mismatch zero effects');

$repo = new ExecutorRepositoryDouble();
$repo->readQueue[] = $persistence(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $scheduled);
$processor = new ExecutorProcessorDouble('business_completion');
$coordinator = new ExecutorCoordinatorDouble();
$assert($make($repo, $processor, $coordinator)->execute('veciahorra_durable_retry_delivery_completion', 70, 1)->code() === DurableRetryExecutionResult::HOOK_MISMATCH, 'hook mismatch');

$resultReflection = new ReflectionClass(DurableRetryExecutionResult::class);
$contextReflection = new ReflectionClass(DurableRetryExecutionContext::class);
$assert($resultReflection->isFinal() && $contextReflection->isFinal(), 'DTOs final');
foreach (array_merge($resultReflection->getProperties(), $contextReflection->getProperties()) as $property) {
    $assert($property->isReadOnly(), "readonly {$property->getName()}");
}

echo "durable retry executor: {$assertions} assertions\n";
