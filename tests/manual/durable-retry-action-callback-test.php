<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Contracts\DurableRetryExecutorInterface;
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
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryActionCallback;
use VeciAhorra\Modules\Orders\Services\DurableRetryExecutor;

final class CallbackExecutorDouble implements DurableRetryExecutorInterface
{
    public array $calls = [];
    public mixed $next;

    public function execute(
        string $hook,
        int $scheduleId,
        int $generation
    ): DurableRetryExecutionResult {
        $this->calls[] = func_get_args();
        if ($this->next instanceof Throwable) {
            throw $this->next;
        }
        return $this->next;
    }
}

final class CallbackRepositoryDouble implements DurableRetryScheduleRepositoryInterface
{
    public array $events;
    public array $reads = [];
    public array $transitions = [];
    public array $readQueue = [];
    public array $transitionQueue = [];

    public function __construct(array &$events) { $this->events =& $events; }
    public function create(array $initialFields): DurableRetryPersistenceResult { throw new LogicException(); }
    public function findById(int $id): DurableRetryPersistenceResult
    {
        $this->events[] = 'read';
        $this->reads[] = $id;
        return array_shift($this->readQueue);
    }
    public function findByIdentity(string $stage, int $subjectId, int $generation): DurableRetryPersistenceResult { throw new LogicException(); }
    public function associateScheduledAction(int $id, int $expectedVersion, int $scheduledActionId, string $dispatchedAt, string $updatedAt): DurableRetryPersistenceResult { throw new LogicException(); }
    public function transition(DurableRetryScheduleSnapshot $expected, DurableRetryScheduleSnapshot $target): DurableRetryPersistenceResult
    {
        $this->events[] = $target->status() === 'claimed' ? 'claim' : 'persist';
        $this->transitions[] = [$expected, $target];
        return array_shift($this->transitionQueue);
    }
    public function supersedeAndCreateNextGeneration(DurableRetryScheduleSnapshot $claimed, DurableRetryNextAttemptDecision $decision, string $supersededAtUtc): DurableRetryNextGenerationPersistenceResult
    {
        throw new LogicException();
    }
}

final class CallbackProcessorDouble implements DurableRetryStageProcessorInterface
{
    public int $calls = 0;
    public function __construct(private array &$events) {}
    public function stage(): string { return 'business_completion'; }
    public function process(DurableRetryExecutionContext $context): DurableRetryProcessingResult
    {
        $this->events[] = 'process';
        ++$this->calls;
        return DurableRetryProcessingResult::succeeded(1);
    }
}

final class CallbackResolverDouble implements DurableRetryStageProcessorResolverInterface
{
    public int $calls = 0;
    public function __construct(
        private readonly DurableRetryStageProcessorInterface $processor,
        private array &$events
    ) {}
    public function resolve(string $stage): DurableRetryStageProcessorInterface
    {
        $this->events[] = 'resolve';
        ++$this->calls;
        return $this->processor;
    }
}

final class CallbackCoordinatorDouble implements DurableRetryExternalScheduleCoordinatorInterface
{
    public int $calls = 0;
    public function coordinate(int $scheduleId, int $generation): DurableRetryCoordinationResult
    {
        ++$this->calls;
        throw new LogicException();
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) { throw new RuntimeException($message); }
};
$result = new DurableRetryExecutionResult(
    DurableRetryExecutionResult::NOT_FOUND,
    70,
    3
);
$executor = new CallbackExecutorDouble();
$executor->next = $result;
$callback = new DurableRetryActionCallback($executor);
$hook = 'veciahorra_durable_retry_business_completion';
$events = ['callback'];
$delegated = $callback->execute($hook, 70, 3);
$events[] = 'return';
$assert($delegated === $result, 'returns exact executor result');
$assert($executor->calls === [[$hook, 70, 3]], 'delegates canonical identity once');
$assert($events === ['callback', 'return'], 'callback adds no intermediate work');

$invalid = [
    [null, 70, 3],
    ['', 70, 3],
    ['unknown', 70, 3],
    [$hook, null, 3],
    [$hook, 0, 3],
    [$hook, -1, 3],
    [$hook, 1.2, 3],
    [$hook, '70', 3],
    [$hook, '7e1', 3],
    [$hook, ' 70', 3],
    [$hook, '70x', 3],
    [$hook, [], 3],
    [$hook, new stdClass(), 3],
    [$hook, 70, null],
    [$hook, 70, 0],
    [$hook, 70, -1],
    [$hook, 70, 1.2],
    [$hook, 70, '3'],
    [$hook, 70, '3e0'],
    [$hook, 70, ' 3'],
    [$hook, 70, '3x'],
    [$hook, 70, []],
    [$hook, 70, new stdClass()],
];
foreach ($invalid as $case) {
    $before = count($executor->calls);
    $caught = null;
    try {
        $callback->execute(...$case);
    } catch (InvalidArgumentException $exception) {
        $caught = $exception;
    }
    $assert($caught instanceof InvalidArgumentException, 'invalid identity rejected');
    $assert($caught?->getMessage() === 'Invalid durable retry callback invocation.', 'invalid message safe');
    $assert(count($executor->calls) === $before, 'invalid identity never delegates');
}

$failure = new RuntimeException('internal executor detail');
$executor->next = $failure;
$caught = null;
try {
    $callback->execute($hook, 70, 3);
} catch (RuntimeException $exception) {
    $caught = $exception;
}
$assert($caught === $failure, 'executor exception propagates unchanged');
$assert(count($executor->calls) === 2, 'throwing executor called once');

$snapshot = static function (string $status, int $version): DurableRetryScheduleSnapshot {
    $terminal = $status === 'consumed';
    return DurableRetryScheduleSnapshot::fromArray([
        'id' => 70, 'public_id' => str_repeat('a', 64),
        'stage' => 'business_completion', 'subject_id' => 800,
        'completion_id' => 700, 'generation' => 1, 'attempt_number' => 0,
        'scheduled_for' => '2030-01-01 00:01:00',
        'scheduled_action_id' => 900, 'dispatch_token_hash' => str_repeat('b', 64),
        'status' => $status, 'active_slot' => $terminal ? null : 1,
        'version' => $version, 'reason_code' => $terminal ? 'retry_consumed' : 'retryable_failure',
        'dispatched_at' => '2030-01-01 00:00:30',
        'claimed_at' => $status === 'scheduled' ? null : '2030-01-01 00:01:00',
        'consumed_at' => $terminal ? '2030-01-01 00:02:00' : null,
        'terminal_at' => $terminal ? '2030-01-01 00:02:00' : null,
        'created_at' => '2030-01-01 00:00:00',
        'updated_at' => $terminal ? '2030-01-01 00:02:00' : ($status === 'claimed' ? '2030-01-01 00:01:00' : '2030-01-01 00:00:30'),
    ]);
};
$scheduled = $snapshot('scheduled', 2);
$claimed = $snapshot('claimed', 3);
$consumed = $snapshot('consumed', 4);
$trace = ['callback'];
$repository = new CallbackRepositoryDouble($trace);
$repository->readQueue[] = new DurableRetryPersistenceResult(
    DurableRetryPersistenceResult::EXISTING_COMPATIBLE,
    $scheduled
);
$repository->transitionQueue[] = new DurableRetryPersistenceResult(
    DurableRetryPersistenceResult::APPLIED,
    $claimed
);
$repository->transitionQueue[] = new DurableRetryPersistenceResult(
    DurableRetryPersistenceResult::APPLIED,
    $consumed
);
$processor = new CallbackProcessorDouble($trace);
$resolver = new CallbackResolverDouble($processor, $trace);
$coordinator = new CallbackCoordinatorDouble();
$times = ['2030-01-01 00:01:00', '2030-01-01 00:02:00'];
$clock = static function () use (&$times): string { return array_shift($times); };
$realExecutor = new DurableRetryExecutor(
    $repository,
    new DurableRetryProcessingPolicy(),
    $coordinator,
    $resolver,
    $clock(...)
);
$integrated = (new DurableRetryActionCallback($realExecutor))
    ->execute($hook, 70, 1);
$assert($integrated->code() === DurableRetryExecutionResult::PROCESSED, 'integrated callback succeeds');
$assert($trace === ['callback', 'read', 'resolve', 'claim', 'process', 'persist'], 'full order preserved');
$assert($resolver->calls === 1 && $processor->calls === 1, 'single resolution and process');
$assert(count($repository->reads) === 1 && count($repository->transitions) === 2, 'existing executor budget preserved');
$assert($coordinator->calls === 0, 'callback performs no scheduling');

echo "durable retry action callback: {$assertions} assertions\n";
