<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Contracts\DurableRetryScheduleRepositoryInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialAuthorityProductionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialScheduleResolutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextAttemptDecision;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextGenerationPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialScheduleResolver;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

final class A6RepositoryDouble implements DurableRetryScheduleRepositoryInterface
{
    public int $reads = 0;
    public int $writes = 0;
    public int $scheduling = 0;
    public int $legacy = 0;
    /** @var list<array{string,int,int}> */
    public array $arguments = [];

    public function __construct(
        private readonly DurableRetryPersistenceResult|Throwable $outcome
    ) {
    }

    public function findByIdentity(
        string $stage,
        int $subjectId,
        int $generation
    ): DurableRetryPersistenceResult {
        ++$this->reads;
        $this->arguments[] = [$stage, $subjectId, $generation];
        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }

    public function create(array $initialFields): DurableRetryPersistenceResult
    {
        ++$this->writes;
        throw new LogicException('write prohibited');
    }

    public function findById(int $id): DurableRetryPersistenceResult
    {
        ++$this->reads;
        throw new LogicException('second read prohibited');
    }

    public function associateScheduledAction(
        int $id,
        int $expectedVersion,
        int $scheduledActionId,
        string $dispatchedAt,
        string $updatedAt
    ): DurableRetryPersistenceResult {
        ++$this->writes;
        throw new LogicException('write prohibited');
    }

    public function transition(
        DurableRetryScheduleSnapshot $expected,
        DurableRetryScheduleSnapshot $target
    ): DurableRetryPersistenceResult {
        ++$this->writes;
        throw new LogicException('write prohibited');
    }

    public function supersedeAndCreateNextGeneration(
        DurableRetryScheduleSnapshot $claimed,
        DurableRetryNextAttemptDecision $decision,
        string $supersededAtUtc
    ): DurableRetryNextGenerationPersistenceResult {
        ++$this->writes;
        throw new LogicException('write prohibited');
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$utc = new DateTimeZone('UTC');
$when = new DateTimeImmutable('2026-01-02 03:04:05', $utc);
$identity = DurableRetryAuthorityIdentity::reconciliation(77);
$request = DurableRetryInitialTransferRequest::reconciliation($identity, 77, $when);
$durable = DurableRetryInitialAuthorityProductionResult::durableExisting(
    DurableRetryLegacyAuthorityResult::durable()
);
$legacy = DurableRetryInitialAuthorityProductionResult::legacyAllowed(
    DurableRetryLegacyAuthorityResult::legacy()
);

$fields = static function (
    string $status = 'dispatching',
    array $changes = []
): array {
    $scheduled = $status === 'scheduled';
    $base = [
        'id' => 91,
        'public_id' => str_repeat('a', 64),
        'stage' => 'reconciliation',
        'subject_id' => 77,
        'completion_id' => 77,
        'generation' => 1,
        'attempt_number' => 0,
        'scheduled_for' => '2026-01-02 03:04:05',
        'scheduled_action_id' => $scheduled ? 501 : null,
        'dispatch_token_hash' => str_repeat('b', 64),
        'status' => $status,
        'active_slot' => 1,
        'version' => $scheduled ? 2 : 1,
        'reason_code' => $scheduled ? 'external_schedule_confirmed' : 'retryable_failure',
        'dispatched_at' => $scheduled ? '2026-01-02 03:04:05' : null,
        'claimed_at' => null,
        'consumed_at' => null,
        'terminal_at' => null,
        'created_at' => '2026-01-02 03:04:05',
        'updated_at' => '2026-01-02 03:04:05',
    ];

    return array_replace($base, $changes);
};
$unsafeSnapshot = static function (array $raw): DurableRetryScheduleSnapshot {
    $reflection = new ReflectionClass(DurableRetryScheduleSnapshot::class);
    $snapshot = $reflection->newInstanceWithoutConstructor();
    $property = $reflection->getProperty('fields');
    $property->setValue($snapshot, $raw);

    return $snapshot;
};
$read = static fn (array $raw): DurableRetryPersistenceResult =>
    new DurableRetryPersistenceResult(
        DurableRetryPersistenceResult::EXISTING_COMPATIBLE,
        $unsafeSnapshot($raw)
    );

$cases = [
    ['A6-01', $read($fields()), $durable, 'resolved_dispatching', 'initial_dispatch_required', 91, 1, 1],
    ['A6-02', $read($fields('scheduled')), $durable, 'resolved_scheduled', 'initial_dispatch_confirmed', 91, 1, 1],
    ['A6-03', $read($fields(changes: ['completion_id' => null])), $durable, 'incompatible', 'initial_schedule_incompatible', null, null, 1],
    ['A6-04', $read($fields()), $durable, 'resolved_dispatching', 'initial_dispatch_required', 91, 1, 1],
    ['A6-05', new DurableRetryPersistenceResult(DurableRetryPersistenceResult::NOT_FOUND), $durable, 'not_found', 'initial_schedule_not_found', null, null, 1],
    ['A6-06', $read($fields(changes: ['subject_id' => 88, 'completion_id' => 88])), $durable, 'incompatible', 'initial_schedule_incompatible', null, null, 1],
    ['A6-07', $read($fields(changes: ['generation' => 2])), $durable, 'incompatible', 'initial_schedule_incompatible', null, null, 1],
    ['A6-08', $read($fields(changes: ['id' => 0])), $durable, 'incompatible', 'initial_schedule_incompatible', null, null, 1],
    ['A6-09', $read($fields('claimed', ['scheduled_action_id' => 501, 'dispatched_at' => '2026-01-02 03:04:05', 'claimed_at' => '2026-01-02 03:04:05'])), $durable, 'incompatible', 'initial_schedule_incompatible', null, null, 1],
    ['A6-10', $read($fields(changes: ['version' => 0])), $durable, 'incompatible', 'initial_schedule_incompatible', null, null, 1],
    ['A6-11', new RuntimeException('database unavailable'), $durable, 'read_error', 'initial_schedule_read_error', null, null, 1],
    ['A6-12', $read($fields()), $legacy, 'incompatible', 'initial_schedule_incompatible', null, null, 0],
];

foreach ($cases as [$id, $outcome, $authority, $state, $reason, $scheduleId, $generation, $reads]) {
    $repository = new A6RepositoryDouble($outcome);
    $result = (new DurableRetryInitialScheduleResolver($repository))->resolve(
        $request,
        $authority
    );
    $resolved = $scheduleId !== null;
    $assert($result->state() === $state, "{$id}: state");
    $assert($result->reason() === $reason, "{$id}: reason");
    $assert($result->scheduleId() === $scheduleId, "{$id}: schedule id");
    $assert($result->generation() === $generation, "{$id}: generation");
    $assert(($result->scheduledForUtc() !== null) === $resolved, "{$id}: date presence");
    $assert($result->mayContinueToA7() === $resolved, "{$id}: continuation");
    $assert($result->permitsLegacy() === false, "{$id}: legacy prohibited");
    $assert($repository->reads === $reads, "{$id}: exact reads");
    $assert(count($repository->arguments) === $reads, "{$id}: one identity read");
    $assert($reads === 0 || $repository->arguments[0] === ['reconciliation', 77, 1], "{$id}: canonical identity");
    $assert($repository->writes === 0, "{$id}: zero writes");
    $assert($repository->scheduling === 0, "{$id}: zero scheduling");
    $assert($repository->legacy === 0, "{$id}: zero legacy");
    $assert($repository->reads <= 1, "{$id}: read budget");
}

if ($assertions !== 168 || count($cases) !== 12) {
    throw new RuntimeException("Unexpected A6 matrix: {$assertions} assertions.");
}

echo "PASS durable-retry-initial-schedule-resolver-test (12 cases, 168 assertions)\n";
