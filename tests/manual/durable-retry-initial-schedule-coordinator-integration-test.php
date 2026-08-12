<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalSchedulerInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryScheduleRepositoryInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleCatalog;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialScheduleResolutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextAttemptDecision;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextGenerationPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryReason;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStatus;
use VeciAhorra\Modules\Orders\Services\DurableRetryExternalScheduleCoordinator;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialScheduleCoordinator;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

const A7_EXPECTED_HOOK = 'veciahorra_durable_retry_reconciliation';
const A7_EXPECTED_GROUP = 'veciahorra-durable-retry';

final class A7IntegrationRepository implements DurableRetryScheduleRepositoryInterface
{
    public int $selects = 0;
    public int $updates = 0;
    public int $inserts = 0;
    public int $associations = 0;
    public bool $concurrentOnAssociate = false;

    public function __construct(public DurableRetryScheduleSnapshot $snapshot)
    {
    }

    public function create(array $initialFields): DurableRetryPersistenceResult
    {
        ++$this->inserts;
        throw new LogicException('INSERT prohibited.');
    }

    public function findById(int $id): DurableRetryPersistenceResult
    {
        ++$this->selects;
        return $id === $this->snapshot->id()
            ? new DurableRetryPersistenceResult(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $this->snapshot)
            : new DurableRetryPersistenceResult(DurableRetryPersistenceResult::NOT_FOUND);
    }

    public function findByIdentity(string $stage, int $subjectId, int $generation): DurableRetryPersistenceResult
    {
        ++$this->selects;
        return new DurableRetryPersistenceResult(DurableRetryPersistenceResult::EXISTING_COMPATIBLE, $this->snapshot);
    }

    public function associateScheduledAction(
        int $id,
        int $expectedVersion,
        int $scheduledActionId,
        string $dispatchedAt,
        string $updatedAt
    ): DurableRetryPersistenceResult {
        ++$this->associations;
        ++$this->selects;
        ++$this->updates;
        $fields = $this->snapshot->toArray();
        $fields['scheduled_action_id'] = $scheduledActionId;
        $fields['status'] = DurableRetryStatus::SCHEDULED;
        $fields['version'] = $expectedVersion + 1;
        $fields['dispatched_at'] = $dispatchedAt;
        $fields['updated_at'] = $updatedAt;
        $this->snapshot = DurableRetryScheduleSnapshot::fromArray($fields);
        ++$this->selects;

        return new DurableRetryPersistenceResult(
            $this->concurrentOnAssociate
                ? DurableRetryPersistenceResult::AUTHORITY_LOST
                : DurableRetryPersistenceResult::APPLIED,
            $this->snapshot
        );
    }

    public function transition(DurableRetryScheduleSnapshot $expected, DurableRetryScheduleSnapshot $target): DurableRetryPersistenceResult
    {
        throw new LogicException('Direct transition prohibited.');
    }

    public function supersedeAndCreateNextGeneration(
        DurableRetryScheduleSnapshot $claimed,
        DurableRetryNextAttemptDecision $decision,
        string $supersededAtUtc
    ): DurableRetryNextGenerationPersistenceResult {
        throw new LogicException('Generation creation prohibited.');
    }
}

final class A7IntegrationScheduler implements DurableRetryExternalSchedulerInterface
{
    public int $pending = 0;
    public int $schedules = 0;
    public int $cancels = 0;
    public ?int $actionId = null;
    public bool $unavailable = false;
    public bool $reuse = false;
    public array $identities = [];
    public array $dates = [];

    public function schedule(string $hook, array $arguments, string $group, string $scheduledFor): DurableRetryExternalScheduleResult
    {
        ++$this->schedules;
        $this->identities[] = [$hook, $arguments, $group];
        $this->dates[] = $scheduledFor;
        if ($this->unavailable) {
            return new DurableRetryExternalScheduleResult(DurableRetryExternalScheduleResult::UNAVAILABLE);
        }
        if ($this->reuse) {
            ++$this->pending;
            return new DurableRetryExternalScheduleResult(DurableRetryExternalScheduleResult::ALREADY_SCHEDULED, $this->actionId);
        }
        $this->actionId ??= 700 + $arguments['schedule_id'];
        return new DurableRetryExternalScheduleResult(DurableRetryExternalScheduleResult::SCHEDULED, $this->actionId);
    }

    public function findPending(string $hook, array $arguments, string $group): DurableRetryExternalScheduleResult
    {
        ++$this->pending;
        $this->identities[] = [$hook, $arguments, $group];
        if ($this->unavailable) {
            return new DurableRetryExternalScheduleResult(DurableRetryExternalScheduleResult::UNAVAILABLE);
        }
        return $this->actionId === null
            ? new DurableRetryExternalScheduleResult(DurableRetryExternalScheduleResult::NOT_FOUND)
            : new DurableRetryExternalScheduleResult(DurableRetryExternalScheduleResult::FOUND, $this->actionId);
    }

    public function cancel(int $scheduledActionId, string $hook, array $arguments, string $group): DurableRetryExternalScheduleResult
    {
        ++$this->cancels;
        $this->actionId = null;
        return new DurableRetryExternalScheduleResult(DurableRetryExternalScheduleResult::CANCELLED, $scheduledActionId);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$fields = static function (int $id, bool $scheduled = false, ?int $actionId = null): array {
    return [
        'id' => $id, 'public_id' => str_repeat('a', 64),
        'stage' => DurableRetryStage::RECONCILIATION, 'subject_id' => $id,
        'completion_id' => $id, 'generation' => 1, 'attempt_number' => 0,
        'scheduled_for' => '2030-01-02 03:04:05',
        'scheduled_action_id' => $actionId, 'dispatch_token_hash' => str_repeat('b', 64),
        'status' => $scheduled ? DurableRetryStatus::SCHEDULED : DurableRetryStatus::DISPATCHING,
        'active_slot' => 1, 'version' => $scheduled ? 2 : 1,
        'reason_code' => DurableRetryReason::RETRYABLE_FAILURE,
        'dispatched_at' => $scheduled ? '2030-01-02 03:05:00' : null,
        'claimed_at' => null, 'consumed_at' => null, 'terminal_at' => null,
        'created_at' => '2030-01-01 00:00:00',
        'updated_at' => $scheduled ? '2030-01-02 03:05:00' : '2030-01-01 00:00:00',
    ];
};
$make = static function (int $id, bool $scheduled = false, ?int $actionId = null) use ($fields): array {
    $snapshot = DurableRetryScheduleSnapshot::fromArray($fields($id, $scheduled, $actionId));
    $repository = new A7IntegrationRepository($snapshot);
    $scheduler = new A7IntegrationScheduler();
    $external = new DurableRetryExternalScheduleCoordinator(
        $repository,
        $scheduler,
        static fn (): string => '2030-01-02 03:05:00'
    );
    $a7 = new DurableRetryInitialScheduleCoordinator($external);
    $date = new DateTimeImmutable('2030-01-02 03:04:05', new DateTimeZone('UTC'));
    $resolution = $scheduled
        ? DurableRetryInitialScheduleResolutionResult::resolvedScheduled($id, 1, $date)
        : DurableRetryInitialScheduleResolutionResult::resolvedDispatching($id, 1, $date);
    return [$a7, $repository, $scheduler, $resolution];
};

$runs = [];

[$a7, $repo, $scheduler, $resolution] = $make(201);
$result = $a7->coordinate($resolution);
$runs[] = [$result, $repo, $scheduler, 201, 'synchronized'];

[$a7, $repo, $scheduler, $resolution] = $make(202);
$scheduler->actionId = 902;
$scheduler->reuse = true;
$result = $a7->coordinate($resolution);
$runs[] = [$result, $repo, $scheduler, 202, 'synchronized'];

[$a7, $repo, $scheduler, $resolution] = $make(203);
$result = $a7->coordinate($resolution);
$runs[] = [$result, $repo, $scheduler, 203, 'synchronized'];

[$a7, $repo, $scheduler, $resolution] = $make(204);
$scheduler->unavailable = true;
$result = $a7->coordinate($resolution);
$runs[] = [$result, $repo, $scheduler, 204, 'external_unavailable'];

[$a7, $repo, $scheduler, $resolution] = $make(205);
$repo->concurrentOnAssociate = true;
$result = $a7->coordinate($resolution);
$runs[] = [$result, $repo, $scheduler, 205, 'synchronized'];

[$a7, $repo, $scheduler, $resolution] = $make(206);
$first = $a7->coordinate($resolution);
$scheduledResolution = DurableRetryInitialScheduleResolutionResult::resolvedScheduled(
    206, 1, new DateTimeImmutable('2030-01-02 03:04:05', new DateTimeZone('UTC'))
);
$second = $a7->coordinate($scheduledResolution);
$runs[] = [$second, $repo, $scheduler, 206, 'already_synchronized'];

foreach ($runs as $index => [$result, $repo, $scheduler, $id, $state]) {
    $label = 'I' . ($index + 1);
    $assert($result->state() === $state, "{$label}: state");
    $assert($result->scheduleId() === $id, "{$label}: id");
    $assert($result->generation() === 1, "{$label}: generation");
    $assert(! $result->permitsLegacy(), "{$label}: no legacy");
    $assert($repo->inserts === 0, "{$label}: no insert");
    $assert($repo->updates <= 1, "{$label}: update budget");
    $assert($repo->associations <= 1, "{$label}: association budget");
    $assert($repo->selects <= 5, "{$label}: select budget");
    $assert($scheduler->schedules <= 1, "{$label}: schedule budget");
    $assert($scheduler->pending <= 2, "{$label}: pending budget");
    $assert($scheduler->cancels <= 1, "{$label}: cancellation budget");
    $assert($repo->snapshot->generation() === 1, "{$label}: persisted generation");
    $assert($repo->snapshot->stage() === DurableRetryStage::RECONCILIATION, "{$label}: persisted stage");
    $assert($result->mayContinueToA8(), "{$label}: closed result");
    if ($scheduler->identities !== []) {
        [$hook, $arguments, $group] = $scheduler->identities[0];
        $assert($hook === A7_EXPECTED_HOOK, "{$label}: hook");
        $assert($group === A7_EXPECTED_GROUP, "{$label}: group");
        $assert($arguments === ['schedule_id' => $id, 'generation' => 1], "{$label}: arguments");
    } else {
        $assert(false, "{$label}: identity exists");
        $assert(false, "{$label}: group exists");
        $assert(false, "{$label}: arguments exist");
    }
    $assert($scheduler->dates === [] || $scheduler->dates[0] === '2030-01-02 03:04:05', "{$label}: persisted date");
    $assert($result->state() === 'external_unavailable' || $result->scheduledActionId() !== null, "{$label}: action semantics");
    $assert($repo->snapshot->version() <= 2, "{$label}: version bounded");
}

$assert(count($runs) === 6, 'exactly six integration scenarios');
$assert($runs[5][2]->schedules === 1, 'concurrent invocations create one effective action');
$assert($runs[5][2]->actionId === $runs[5][0]->scheduledActionId(), 'concurrent action identity converges');
$assert($runs[4][0]->reason() === 'concurrent_convergence', 'uncertain CAS converges without second schedule');
$assert($runs[3][1]->snapshot->status() === DurableRetryStatus::DISPATCHING, 'unavailable preserves durable row');
$assert($runs[1][1]->snapshot->status() === DurableRetryStatus::SCHEDULED, 'pending reuse associates');
$assert($runs[2][1]->snapshot->status() === DurableRetryStatus::SCHEDULED, 'new schedule associates');
$assert($runs[2][1]->snapshot->version() === 2, 'new schedule increments version');
$assert($runs[0][2]->schedules === 1, 'A6 to A7 invokes coordinator once');
$assert($runs[4][2]->schedules === 1, 'convergence has zero second schedule');

if ($assertions !== 130) {
    throw new RuntimeException("Unexpected integration total {$assertions}.");
}
echo "durable retry initial schedule coordinator integration: 6 scenarios, {$assertions} assertions\n";
