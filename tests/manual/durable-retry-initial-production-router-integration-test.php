<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialAuthorityProducerInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialScheduleResolverInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacySchedulerInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryCoordinationResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialAuthorityProductionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialProductionRoutingResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialScheduleResolutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialSchedulingResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityResult;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialProductionRouter;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

final class A8IntegrationAuthority implements DurableRetryInitialAuthorityProducerInterface
{
    public int $calls = 0; public array $requests = [];
    public function __construct(private readonly DurableRetryInitialAuthorityProductionResult $outcome) {}
    public function produceReconciliation(DurableRetryInitialTransferRequest $request): DurableRetryInitialAuthorityProductionResult
    { ++$this->calls; $this->requests[] = $request; return $this->outcome; }
}
final class A8IntegrationResolver implements DurableRetryInitialScheduleResolverInterface
{
    public int $calls = 0;
    public function __construct(private readonly DurableRetryInitialScheduleResolutionResult $outcome) {}
    public function resolve(DurableRetryInitialTransferRequest $request, DurableRetryInitialAuthorityProductionResult $authority): DurableRetryInitialScheduleResolutionResult
    { ++$this->calls; return $this->outcome; }
}
final class A8IntegrationCoordinator implements DurableRetryInitialScheduleCoordinatorInterface
{
    public int $calls = 0;
    public function __construct(private readonly DurableRetryInitialSchedulingResult $outcome) {}
    public function coordinate(DurableRetryInitialScheduleResolutionResult $resolution): DurableRetryInitialSchedulingResult
    { ++$this->calls; return $this->outcome; }
}
final class A8IntegrationLegacy implements DurableRetryLegacySchedulerInterface
{
    public int $calls = 0; public array $ids = [];
    public function __construct(private readonly bool|Throwable $outcome) {}
    public function scheduleReconciliation(int $reconciliationId): bool
    {
        ++$this->calls; $this->ids[] = $reconciliationId;
        if ($this->outcome instanceof Throwable) { throw $this->outcome; }
        return $this->outcome;
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions; if (! $condition) { throw new RuntimeException($message); }
};
$utc = new DateTimeImmutable('2031-02-03 04:05:06', new DateTimeZone('UTC'));
$legacyResult = DurableRetryInitialAuthorityProductionResult::legacyAllowed(DurableRetryLegacyAuthorityResult::legacy());
$durableResult = DurableRetryInitialAuthorityProductionResult::durableExisting(DurableRetryLegacyAuthorityResult::durable());
$dispatching = DurableRetryInitialScheduleResolutionResult::resolvedDispatching(71, 1, $utc);
$scheduled = DurableRetryInitialScheduleResolutionResult::resolvedScheduled(71, 1, $utc);
$coord = static fn (string $code, ?int $action, bool $intervention = false): DurableRetryCoordinationResult =>
    new DurableRetryCoordinationResult($code, 71, 1, $action, false, $intervention);
$outcomes = [
    'sync' => DurableRetryInitialSchedulingResult::synchronized($coord(DurableRetryCoordinationResult::SYNCHRONIZED_NEW, 901)),
    'already' => DurableRetryInitialSchedulingResult::alreadySynchronized($coord(DurableRetryCoordinationResult::ALREADY_SYNCHRONIZED, 901)),
    'unavailable' => DurableRetryInitialSchedulingResult::externalUnavailable($coord(DurableRetryCoordinationResult::EXTERNAL_UNAVAILABLE, null)),
    'failed' => DurableRetryInitialSchedulingResult::coordinationFailed($coord(DurableRetryCoordinationResult::INELIGIBLE_STATE, null)),
    'uncertain' => DurableRetryInitialSchedulingResult::coordinationUncertain($coord(DurableRetryCoordinationResult::EXTERNAL_ERROR, null, true)),
];
$specs = [
    ['I01', 101, $legacyResult, $dispatching, $outcomes['sync'], true, 'legacy_scheduled', 1, [1,1,0,0]],
    ['I02', 102, $legacyResult, $dispatching, $outcomes['sync'], false, 'legacy_unavailable', 1, [1,1,0,0]],
    ['I03', 103, $legacyResult, $dispatching, $outcomes['sync'], new RuntimeException('legacy'), 'dependency_failure', 1, [1,1,0,0]],
    ['I04', 104, $durableResult, $dispatching, $outcomes['sync'], false, 'durable_synchronized', 1, [1,0,1,1]],
    ['I05', 105, $durableResult, $scheduled, $outcomes['already'], false, 'durable_already_synchronized', 1, [1,0,1,1]],
    ['I06', 106, $durableResult, $dispatching, $outcomes['unavailable'], false, 'durable_external_unavailable', 1, [1,0,1,1]],
    ['I07', 107, $durableResult, $dispatching, $outcomes['failed'], false, 'durable_coordination_failed', 1, [1,0,1,1]],
    ['I08', 108, $durableResult, $dispatching, $outcomes['uncertain'], false, 'durable_coordination_uncertain', 1, [1,0,1,1]],
    ['I09', 109, $durableResult, DurableRetryInitialScheduleResolutionResult::notFound(), $outcomes['sync'], false, 'resolution_failed', 1, [1,0,1,0]],
    ['I10', 110, $legacyResult, $dispatching, $outcomes['sync'], true, 'legacy_scheduled', 2, [2,2,0,0]],
];

foreach ($specs as [$label, $id, $authorityOutcome, $resolutionOutcome, $coordinationOutcome, $legacyOutcome, $expectedState, $invocations, $calls]) {
    $a5 = new A8IntegrationAuthority($authorityOutcome);
    $a6 = new A8IntegrationResolver($resolutionOutcome);
    $a7 = new A8IntegrationCoordinator($coordinationOutcome);
    $legacy = new A8IntegrationLegacy($legacyOutcome);
    $router = new DurableRetryInitialProductionRouter($a5, $a6, $a7, $legacy);
    $result = null;
    for ($iteration = 0; $iteration < $invocations; ++$iteration) {
        $result = $router->routeReconciliation($id, $utc);
    }
    [$a5Calls, $legacyCalls, $a6Calls, $a7Calls] = $calls;
    $assert($result instanceof DurableRetryInitialProductionRoutingResult, "{$label}: typed");
    $assert($result->state() === $expectedState, "{$label}: state");
    $assert($result->reason() !== '', "{$label}: reason");
    $assert($result->reconciliationId() === $id, "{$label}: id");
    $assert($a5->calls === $a5Calls, "{$label}: A5");
    $assert($legacy->calls === $legacyCalls, "{$label}: legacy");
    $assert($a6->calls === $a6Calls, "{$label}: A6");
    $assert($a7->calls === $a7Calls, "{$label}: A7");
    $assert($a5->calls === $invocations, "{$label}: one A5 per invocation");
    $assert($legacy->calls <= $invocations, "{$label}: legacy budget");
    $assert($a6->calls <= $invocations, "{$label}: A6 budget");
    $assert($a7->calls <= $invocations, "{$label}: A7 budget");
    $assert(! ($legacy->calls > 0 && ($a6->calls > 0 || $a7->calls > 0)), "{$label}: exclusive");
    $assert(count($a5->requests) === $invocations, "{$label}: one request per invocation");
    $assert($a5->requests[0]->authority()->subjectId() === $id, "{$label}: subject");
    $assert($a5->requests[0]->completionId() === $id, "{$label}: completion");
    $assert($a5->requests[0]->generation() === 1, "{$label}: generation");
    $assert($a5->requests[0]->scheduledForDatabase() === '2031-02-03 04:05:06', "{$label}: date");
    $assert(! str_starts_with($expectedState, 'durable_') || ! $result->permitsLegacy(), "{$label}: no fallback");
    $assert($result->legacyScheduledFlag() === ($expectedState === 'legacy_scheduled'), "{$label}: legacy flag");
}
if (count($specs) !== 10 || $assertions !== 200) { throw new RuntimeException("Unexpected integration total {$assertions}."); }
echo "durable retry initial production router integration: 10 scenarios, {$assertions} assertions\n";
