<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Modules/Orders/Contracts/DurableRetryScheduleRepositoryInterface.php';
require_once __DIR__ . '/../../app/Modules/Orders/Contracts/DurableRetryExternalSchedulerInterface.php';
require_once __DIR__ . '/../../app/Modules/Orders/Contracts/DurableRetryExternalScheduleCoordinatorInterface.php';
require_once __DIR__ . '/../../app/Modules/Orders/Domain/DurableRetry/DurableRetryStatus.php';
require_once __DIR__ . '/../../app/Modules/Orders/Domain/DurableRetry/DurableRetryStage.php';
require_once __DIR__ . '/../../app/Modules/Orders/Domain/DurableRetry/DurableRetryReason.php';
require_once __DIR__ . '/../../app/Modules/Orders/Domain/DurableRetry/DurableRetryScheduleSnapshot.php';
require_once __DIR__ . '/../../app/Modules/Orders/Domain/DurableRetry/DurableRetryPersistenceResult.php';
require_once __DIR__ . '/../../app/Modules/Orders/Domain/DurableRetry/DurableRetryExternalScheduleResult.php';
require_once __DIR__ . '/../../app/Modules/Orders/Domain/DurableRetry/DurableRetryExternalScheduleCatalog.php';
require_once __DIR__ . '/../../app/Modules/Orders/Domain/DurableRetry/DurableRetryCoordinationResult.php';
require_once __DIR__ . '/../../app/Modules/Orders/Services/DurableRetryExternalScheduleCoordinator.php';

use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalSchedulerInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryScheduleRepositoryInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryCoordinationResult as Coordination;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleCatalog as Catalog;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleResult as External;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryPersistenceResult as Persistence;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryReason;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStatus;
use VeciAhorra\Modules\Orders\Services\DurableRetryExternalScheduleCoordinator;

final class CoordinatorRepositoryDouble implements DurableRetryScheduleRepositoryInterface
{
    public array $reads = [];
    public array $associations = [];
    public array $operations = [];
    public array $readQueue = [];
    public array $associateQueue = [];

    public function create(array $initialFields): Persistence
    {
        throw new LogicException('Unexpected create.');
    }

    public function findById(int $id): Persistence
    {
        $this->reads[] = $id;
        $this->operations[] = 'read';
        $next = array_shift($this->readQueue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function findByIdentity(
        string $stage,
        int $subjectId,
        int $generation
    ): Persistence {
        throw new LogicException('Unexpected identity read.');
    }

    public function associateScheduledAction(
        int $id,
        int $expectedVersion,
        int $scheduledActionId,
        string $dispatchedAt,
        string $updatedAt
    ): Persistence {
        $this->associations[] = func_get_args();
        $this->operations[] = 'cas';
        $next = array_shift($this->associateQueue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function transition(
        DurableRetryScheduleSnapshot $expected,
        DurableRetryScheduleSnapshot $target
    ): Persistence {
        throw new LogicException('Unexpected transition.');
    }
}

final class CoordinatorSchedulerDouble implements DurableRetryExternalSchedulerInterface
{
    public array $scheduleCalls = [];
    public array $findCalls = [];
    public array $cancelCalls = [];
    public array $operations = [];
    public array $scheduleQueue = [];
    public array $findQueue = [];
    public array $cancelQueue = [];

    public function schedule(
        string $hook,
        array $arguments,
        string $group,
        string $scheduledFor
    ): External {
        $this->scheduleCalls[] = func_get_args();
        $this->operations[] = 'schedule';
        $next = array_shift($this->scheduleQueue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function findPending(
        string $hook,
        array $arguments,
        string $group
    ): External {
        $this->findCalls[] = func_get_args();
        $this->operations[] = 'find';
        $next = array_shift($this->findQueue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function cancel(
        int $scheduledActionId,
        string $hook,
        array $arguments,
        string $group
    ): External {
        $this->cancelCalls[] = func_get_args();
        $this->operations[] = 'cancel';
        $next = array_shift($this->cancelQueue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$fields = static function (
    string $status = DurableRetryStatus::DISPATCHING,
    int $generation = 3,
    ?int $actionId = null,
    int $version = 5,
    string $stage = DurableRetryStage::RECONCILIATION
): array {
    $associated = $actionId !== null;

    return [
        'id' => 41,
        'public_id' => str_repeat('a', 64),
        'stage' => $stage,
        'subject_id' => 77,
        'completion_id' => $stage === DurableRetryStage::RECONCILIATION ? 77 : null,
        'generation' => $generation,
        'attempt_number' => 2,
        'scheduled_for' => '2030-01-02 03:04:05',
        'scheduled_action_id' => $actionId,
        'dispatch_token_hash' => str_repeat('b', 64),
        'status' => $status,
        'active_slot' => DurableRetryStatus::isActive($status) ? 1 : null,
        'version' => $version,
        'reason_code' => in_array($status, [
            DurableRetryStatus::DISPATCHING,
            DurableRetryStatus::SCHEDULED,
            DurableRetryStatus::CLAIMED,
        ], true) ? DurableRetryReason::RETRYABLE_FAILURE
            : DurableRetryReason::SCHEDULING_FAILED,
        'dispatched_at' => $associated ? '2030-01-02 03:05:00' : null,
        'claimed_at' => $status === DurableRetryStatus::CLAIMED
            ? '2030-01-02 03:06:00' : null,
        'consumed_at' => null,
        'terminal_at' => DurableRetryStatus::isActive($status)
            ? null : '2030-01-02 03:07:00',
        'created_at' => '2030-01-01 00:00:00',
        'updated_at' => $associated
            ? '2030-01-02 03:05:00' : '2030-01-01 00:00:00',
    ];
};
$snapshot = static fn (array $value): DurableRetryScheduleSnapshot =>
    DurableRetryScheduleSnapshot::fromArray($value);
$read = static fn (DurableRetryScheduleSnapshot $value): Persistence =>
    new Persistence(Persistence::EXISTING_COMPATIBLE, $value);
$persist = static fn (
    string $code,
    ?DurableRetryScheduleSnapshot $value = null
): Persistence => new Persistence($code, $value);
$external = static fn (string $code, ?int $id = null): External =>
    new External($code, $id);
$scheduled = static function (
    array $base,
    int $id,
    ?int $generation = null
) use ($snapshot): DurableRetryScheduleSnapshot {
    $base['scheduled_action_id'] = $id;
    $base['status'] = DurableRetryStatus::SCHEDULED;
    $base['version']++;
    $base['dispatched_at'] = '2030-01-02 03:05:00';
    $base['updated_at'] = '2030-01-02 03:05:00';
    if ($generation !== null) {
        $base['generation'] = $generation;
    }

    return $snapshot($base);
};
$run = static function (
    array $reads,
    array $associations = [],
    array $schedules = [],
    array $finds = [],
    array $cancels = [],
    ?Closure $clock = null
): array {
    $repository = new CoordinatorRepositoryDouble();
    $repository->readQueue = $reads;
    $repository->associateQueue = $associations;
    $scheduler = new CoordinatorSchedulerDouble();
    $scheduler->scheduleQueue = $schedules;
    $scheduler->findQueue = $finds;
    $scheduler->cancelQueue = $cancels;
    $service = new DurableRetryExternalScheduleCoordinator(
        $repository,
        $scheduler,
        $clock ?? static fn (): string => '2030-01-02 03:05:00'
    );

    return [$service->coordinate(41, 3), $repository, $scheduler];
};

$base = $fields();
$dispatching = $snapshot($base);
$associated100 = $scheduled($base, 100);

[$result, $repo, $scheduler] = $run(
    [$read($dispatching), $read($dispatching)],
    [$persist(Persistence::APPLIED, $associated100)],
    [$external(External::SCHEDULED, 100)]
);
$assert($result->code() === Coordination::SYNCHRONIZED_NEW, 'new action synchronized');
$assert($result->succeeded() && $result->scheduledActionId() === 100, 'new result safe');
$assert(count($repo->reads) === 2 && count($repo->associations) === 1, 'new durable budget');
$assert(count($scheduler->scheduleCalls) === 1 && $scheduler->cancelCalls === [], 'new external budget');
$assert($repo->associations[0] === [41, 5, 100, '2030-01-02 03:05:00', '2030-01-02 03:05:00'], 'exact CAS');
$assert($scheduler->scheduleCalls[0] === [
    Catalog::RECONCILIATION,
    ['schedule_id' => 41, 'generation' => 3],
    Catalog::GROUP,
    '2030-01-02 03:04:05',
], 'canonical external identity');

[$result, $repo, $scheduler] = $run(
    [$read($associated100)],
    finds: [$external(External::FOUND, 100)]
);
$assert($result->code() === Coordination::ALREADY_SYNCHRONIZED, 'repeat converges');
$assert(count($repo->reads) === 1 && $repo->associations === [], 'repeat durable budget');
$assert($scheduler->scheduleCalls === [] && count($scheduler->findCalls) === 1, 'repeat external budget');

[$result, $repo, $scheduler] = $run(
    [$read($dispatching), $read($dispatching)],
    [$persist(Persistence::APPLIED, $associated100)],
    [$external(External::ALREADY_SCHEDULED, 100)]
);
$assert($result->code() === Coordination::SYNCHRONIZED_EXISTING, 'existing action associated');
$assert(count($scheduler->scheduleCalls) === 1 && $scheduler->cancelCalls === [], 'existing budget');

[$result, $repo, $scheduler] = $run(
    [$read($dispatching), $read($dispatching)],
    [$persist(Persistence::ALREADY_APPLIED, $associated100)],
    [$external(External::SCHEDULED, 100)]
);
$assert($result->code() === Coordination::CONCURRENT_CONVERGENCE, 'same id CAS converges');
$assert($scheduler->cancelCalls === [], 'winner id never cancelled');

$associated200 = $scheduled($base, 200);
[$result, $repo, $scheduler] = $run(
    [$read($dispatching), $read($dispatching), $read($associated200)],
    [$persist(Persistence::CONFLICT, $associated200)],
    [$external(External::SCHEDULED, 100)],
    cancels: [$external(External::CANCELLED, 100)]
);
$assert($result->code() === Coordination::CONFLICT_COMPENSATED, 'different id conflict compensated');
$assert($result->compensated() && ! $result->succeeded(), 'compensation flags');
$assert($scheduler->cancelCalls[0][0] === 100, 'loser cancels own id');
$assert($scheduler->cancelCalls[0][0] !== 200, 'loser never cancels winner');
$assert(count($repo->reads) === 3 && count($repo->associations) === 1, 'conflict durable budget');

$generation4 = $snapshot($fields(generation: 4));
[$result, $repo, $scheduler] = $run([$read($generation4)]);
$assert($result->code() === Coordination::STALE_GENERATION, 'initial stale generation');
$assert($scheduler->scheduleCalls === [] && $scheduler->findCalls === [] && $scheduler->cancelCalls === [], 'stale has no external calls');
$assert(count($repo->reads) === 1 && $repo->associations === [], 'stale durable budget');

[$result, $repo, $scheduler] = $run([$read($dispatching), $read($generation4)]);
$assert($result->code() === Coordination::STALE_GENERATION, 'generation changed before scheduling');
$assert($scheduler->scheduleCalls === [], 'pre-schedule race closes externally');

$failed = $snapshot($fields(DurableRetryStatus::FAILED));
[$result, $repo, $scheduler] = $run([$read($failed)]);
$assert($result->code() === Coordination::INELIGIBLE_STATE, 'ineligible closes');
$assert($scheduler->scheduleCalls === [] && $scheduler->findCalls === [], 'ineligible no external calls');

$claimed = $snapshot($fields(DurableRetryStatus::CLAIMED, actionId: 100));
[$result, $repo, $scheduler] = $run([$read($claimed)]);
$assert($result->code() === Coordination::INELIGIBLE_STATE, 'claimed is valid but ineligible');
$assert($scheduler->findCalls === [] && $scheduler->scheduleCalls === [], 'claimed performs no external calls');

[$result, $repo, $scheduler] = $run([$persist(Persistence::NOT_FOUND)]);
$assert($result->code() === Coordination::NOT_FOUND, 'missing schedule closes');
$assert(count($repo->reads) === 1 && $scheduler->scheduleCalls === [], 'missing budget');

[$result, $repo, $scheduler] = $run(
    [$read($dispatching), $read($dispatching), $read($generation4)],
    [$persist(Persistence::AUTHORITY_LOST, $generation4)],
    [$external(External::SCHEDULED, 100)],
    cancels: [$external(External::CANCELLED, 100)]
);
$assert($result->code() === Coordination::CONFLICT_COMPENSATED, 'generation change after scheduling compensated');
$assert($scheduler->cancelCalls[0][0] === 100, 'post-schedule generation race exact cancel');

[$result, $repo, $scheduler] = $run(
    [$read($dispatching), $read($dispatching), $read($failed)],
    [$persist(Persistence::UNEXPECTED_STATE, $failed)],
    [$external(External::SCHEDULED, 101)],
    cancels: [$external(External::ALREADY_ABSENT)]
);
$assert($result->code() === Coordination::CONFLICT_COMPENSATED && $result->compensated(), 'state race absent compensated');

[$result, $repo, $scheduler] = $run(
    [$read($dispatching), $read($dispatching), $persist(Persistence::NOT_FOUND)],
    [$persist(Persistence::NOT_FOUND)],
    [$external(External::SCHEDULED, 102)],
    cancels: [$external(External::CANCELLED, 102)]
);
$assert($result->code() === Coordination::CONFLICT_COMPENSATED, 'disappearance compensated');

[$result, $repo, $scheduler] = $run(
    [$read($dispatching), $read($dispatching), $read($associated100)],
    [$persist(Persistence::CONFLICT, $associated100)],
    [$external(External::SCHEDULED, 100)]
);
$assert($result->code() === Coordination::CONCURRENT_CONVERGENCE, 'same id on reread converges');
$assert($scheduler->cancelCalls === [], 'convergent reread never cancels');

[$result, $repo, $scheduler] = $run(
    [$read($dispatching), $read($dispatching), $read($associated200)],
    [$persist(Persistence::CONFLICT, $associated200)],
    [$external(External::SCHEDULED, 103)],
    cancels: [$external(External::EXTERNAL_ERROR)]
);
$assert($result->code() === Coordination::COMPENSATION_UNCONFIRMED, 'failed compensation explicit');
$assert($result->interventionRequired() && ! $result->compensated(), 'failed compensation flags');

[$result] = $run(
    [$read($associated100)],
    finds: [$external(External::EXTERNAL_ERROR)]
);
$assert($result->code() === Coordination::EXTERNAL_ERROR, 'multiple pending is external error');
$assert($result->interventionRequired(), 'multiple pending requires intervention');

[$result] = $run(
    [$read($associated100)],
    finds: [$external(External::NOT_FOUND)]
);
$assert($result->code() === Coordination::EXTERNAL_INCONSISTENCY, 'associated action missing');

[$result] = $run(
    [$read($associated100)],
    finds: [$external(External::FOUND, 999)]
);
$assert($result->code() === Coordination::EXTERNAL_INCONSISTENCY, 'associated action mismatched');
$assert($result->scheduledActionId() === 100, 'result exposes durable id not foreign id');

[$result] = $run(
    [$read($associated100)],
    finds: [$external(External::UNAVAILABLE)]
);
$assert($result->code() === Coordination::EXTERNAL_UNAVAILABLE, 'provider unavailable');

[$result, $repo, $scheduler] = $run(
    [$persist(Persistence::PERSISTENCE_ERROR)]
);
$assert($result->code() === Coordination::PERSISTENCE_ERROR, 'initial repository failure');
$assert($scheduler->scheduleCalls === [] && $scheduler->findCalls === [], 'repository failure before provider');

[$result, $repo, $scheduler] = $run(
    [$read($dispatching), $read($dispatching), $persist(Persistence::PERSISTENCE_ERROR)],
    [new RuntimeException('database')],
    [$external(External::SCHEDULED, 104)],
    cancels: [$external(External::CANCELLED, 104)]
);
$assert($result->code() === Coordination::PERSISTENCE_ERROR, 'post-create repository failure retained');
$assert($result->compensated() && ! $result->interventionRequired(), 'post-create repository failure compensated');
$assert(count($repo->reads) === 3 && count($scheduler->cancelCalls) === 1, 'post-create failure budget');

[$result] = $run(
    [$read($dispatching), $read($dispatching)],
    schedules: [$external(External::UNAVAILABLE)]
);
$assert($result->code() === Coordination::EXTERNAL_UNAVAILABLE, 'schedule unavailable');

[$result] = $run(
    [$read($dispatching), $read($dispatching)],
    schedules: [$external(External::EXTERNAL_ERROR)]
);
$assert($result->code() === Coordination::EXTERNAL_ERROR, 'schedule external error');

[$result, $repo, $scheduler] = $run(
    [$read($dispatching), $read($dispatching), $read($associated200)],
    [$persist(Persistence::CONFLICT, $associated200)],
    [$external(External::ALREADY_SCHEDULED, 100)]
);
$assert($result->code() === Coordination::DURABLE_INCONSISTENCY, 'recovered foreign action not cancelled');
$assert($result->interventionRequired() && $scheduler->cancelCalls === [], 'only newly created action compensated');

foreach ([
    DurableRetryStage::RECONCILIATION => Catalog::RECONCILIATION,
    DurableRetryStage::BUSINESS_COMPLETION => Catalog::BUSINESS_COMPLETION,
    DurableRetryStage::DELIVERY_COMPLETION => Catalog::DELIVERY_COMPLETION,
    DurableRetryStage::FULFILLMENT_COMPLETION => Catalog::FULFILLMENT_COMPLETION,
] as $stage => $hook) {
    $stageSnapshot = $snapshot($fields(stage: $stage));
    [$result, $repo, $scheduler] = $run(
        [$read($stageSnapshot), $read($stageSnapshot)],
        [$persist(Persistence::APPLIED, $scheduled($fields(stage: $stage), 110))],
        [$external(External::SCHEDULED, 110)]
    );
    $assert($result->succeeded(), "stage {$stage} coordinates");
    $assert($scheduler->scheduleCalls[0][0] === $hook, "stage {$stage} maps hook");
}

$assert(Catalog::hooks() === [
    Catalog::RECONCILIATION,
    Catalog::BUSINESS_COMPLETION,
    Catalog::DELIVERY_COMPLETION,
    Catalog::FULFILLMENT_COMPLETION,
], 'closed productive hook allowlist');

echo "durable retry external schedule coordinator: {$assertions} assertions\n";
