<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryCoordinationResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialScheduleResolutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialSchedulingResult;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialScheduleCoordinator;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

final class A7CoordinatorDouble implements DurableRetryExternalScheduleCoordinatorInterface
{
    public int $calls = 0;
    public array $arguments = [];

    public function __construct(
        private readonly DurableRetryCoordinationResult|Throwable $outcome
    ) {
    }

    public function coordinate(int $scheduleId, int $generation): DurableRetryCoordinationResult
    {
        ++$this->calls;
        $this->arguments[] = [$scheduleId, $generation];
        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$utc = new DateTimeImmutable('2026-07-31 12:00:00', new DateTimeZone('UTC'));
$resolved = static fn (string $state, int $id): DurableRetryInitialScheduleResolutionResult =>
    $state === DurableRetryInitialScheduleResolutionResult::RESOLVED_SCHEDULED
        ? DurableRetryInitialScheduleResolutionResult::resolvedScheduled($id, 1, $utc)
        : DurableRetryInitialScheduleResolutionResult::resolvedDispatching($id, 1, $utc);
$coordination = static fn (
    string $code,
    int $id,
    ?int $actionId,
    bool $intervention = false,
    bool $compensated = false
): DurableRetryCoordinationResult => new DurableRetryCoordinationResult(
    $code,
    $id,
    1,
    $actionId,
    $compensated,
    $intervention
);

$cases = [
    ['F01', $resolved('resolved_dispatching', 101), $coordination('already_synchronized', 101, 501), 'already_synchronized', 'already_synchronized', 501, false, false],
    ['F02', $resolved('resolved_dispatching', 102), $coordination('synchronized_new', 102, 502), 'synchronized', 'synchronized_new', 502, false, false],
    ['F03', $resolved('resolved_dispatching', 103), $coordination('conflict_compensated', 103, 503, false, true), 'coordination_failed', 'conflict_compensated', 503, false, false],
    ['F04', $resolved('resolved_dispatching', 104), $coordination('compensation_unconfirmed', 104, 504, true), 'coordination_uncertain', 'compensation_unconfirmed', 504, true, false],
    ['F05', $resolved('resolved_dispatching', 105), $coordination('external_unavailable', 105, null), 'external_unavailable', 'external_unavailable', null, false, false],
    ['F06', $resolved('resolved_scheduled', 106), $coordination('already_synchronized', 106, 506), 'already_synchronized', 'already_synchronized', 506, false, false],
    ['F07', $resolved('resolved_scheduled', 107), $coordination('already_synchronized', 107, 507), 'already_synchronized', 'already_synchronized', 507, false, false],
    ['F08', $resolved('resolved_scheduled', 108), $coordination('external_inconsistency', 108, 508, true), 'coordination_uncertain', 'external_inconsistency', 508, true, false],
    ['F09', $resolved('resolved_scheduled', 109), $coordination('external_inconsistency', 109, 999, true), 'coordination_uncertain', 'external_inconsistency', 999, true, false],
    ['F10', DurableRetryInitialScheduleResolutionResult::incompatible(), $coordination('synchronized_new', 110, 510), null, null, null, false, true],
    ['F11', $resolved('resolved_dispatching', 111), new RuntimeException('external'), 'coordination_uncertain', 'external_error', null, true, false],
    ['F12', $resolved('resolved_dispatching', 112), $coordination('concurrent_convergence', 112, 512), 'synchronized', 'concurrent_convergence', 512, false, false],
];

foreach ($cases as [$id, $resolution, $outcome, $state, $reason, $actionId, $intervention, $throws]) {
    $double = new A7CoordinatorDouble($outcome);
    $service = new DurableRetryInitialScheduleCoordinator($double);
    $result = null;
    $caught = false;
    try {
        $result = $service->coordinate($resolution);
    } catch (InvalidArgumentException) {
        $caught = true;
    }
    $expectedCalls = $throws ? 0 : 1;
    $expectedId = $resolution->scheduleId();
    $assert($caught === $throws, "{$id}: closed input");
    $assert(($result instanceof DurableRetryInitialSchedulingResult) === ! $throws, "{$id}: typed result");
    $assert($throws || $result->state() === $state, "{$id}: state");
    $assert($throws || $result->reason() === $reason, "{$id}: reason");
    $assert($throws || $result->scheduledActionId() === $actionId, "{$id}: action");
    $assert($throws || $result->scheduleId() === $expectedId, "{$id}: schedule id");
    $assert($throws || $result->generation() === 1, "{$id}: generation");
    $assert($throws || $result->requiresIntervention() === $intervention, "{$id}: intervention");
    $assert($throws || ! $result->permitsLegacy(), "{$id}: no legacy");
    $assert($throws || $result->mayContinueToA8(), "{$id}: typed closure");
    $assert($double->calls === $expectedCalls, "{$id}: one coordinator maximum");
    $assert(count($double->arguments) === $expectedCalls, "{$id}: no second call");
    $assert($throws || $double->arguments[0] === [$expectedId, 1], "{$id}: identity");
    $assert(! property_exists($service, 'repository'), "{$id}: no repository");
    $assert(! property_exists($service, 'scheduler'), "{$id}: no adapter/hooks/A8/A9");
}

if (count($cases) !== 12 || $assertions !== 180) {
    throw new RuntimeException("Unexpected pre-total {$assertions}.");
}
echo "durable retry initial schedule coordinator: 12 cases, {$assertions} assertions\n";
