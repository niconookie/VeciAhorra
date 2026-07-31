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

final class A8AuthorityDouble implements DurableRetryInitialAuthorityProducerInterface
{
    public int $calls = 0;
    public ?DurableRetryInitialTransferRequest $request = null;
    public function __construct(private readonly DurableRetryInitialAuthorityProductionResult|Throwable $outcome) {}
    public function produceReconciliation(DurableRetryInitialTransferRequest $request): DurableRetryInitialAuthorityProductionResult
    {
        ++$this->calls; $this->request = $request;
        if ($this->outcome instanceof Throwable) { throw $this->outcome; }
        return $this->outcome;
    }
}
final class A8ResolverDouble implements DurableRetryInitialScheduleResolverInterface
{
    public int $calls = 0;
    public function __construct(private readonly DurableRetryInitialScheduleResolutionResult|Throwable $outcome) {}
    public function resolve(DurableRetryInitialTransferRequest $request, DurableRetryInitialAuthorityProductionResult $authority): DurableRetryInitialScheduleResolutionResult
    {
        ++$this->calls;
        if ($this->outcome instanceof Throwable) { throw $this->outcome; }
        return $this->outcome;
    }
}
final class A8ScheduleDouble implements DurableRetryInitialScheduleCoordinatorInterface
{
    public int $calls = 0;
    public function __construct(private readonly DurableRetryInitialSchedulingResult|Throwable $outcome) {}
    public function coordinate(DurableRetryInitialScheduleResolutionResult $resolution): DurableRetryInitialSchedulingResult
    {
        ++$this->calls;
        if ($this->outcome instanceof Throwable) { throw $this->outcome; }
        return $this->outcome;
    }
}
final class A8LegacyDouble implements DurableRetryLegacySchedulerInterface
{
    public int $calls = 0;
    public function __construct(private readonly bool|Throwable $outcome) {}
    public function scheduleReconciliation(int $reconciliationId): bool
    {
        ++$this->calls;
        if ($this->outcome instanceof Throwable) { throw $this->outcome; }
        return $this->outcome;
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) { throw new RuntimeException($message); }
};
$utc = new DateTimeImmutable('2030-01-02 03:04:05', new DateTimeZone('UTC'));
$legacyAuthority = DurableRetryLegacyAuthorityResult::legacy();
$durableAuthority = DurableRetryLegacyAuthorityResult::durable();
$legacy = DurableRetryInitialAuthorityProductionResult::legacyAllowed($legacyAuthority);
$durable = DurableRetryInitialAuthorityProductionResult::durableExisting($durableAuthority);
$closed = DurableRetryInitialAuthorityProductionResult::operationalFailure(null, DurableRetryInitialAuthorityProductionResult::DEPENDENCY_FAILURE);
$dispatching = DurableRetryInitialScheduleResolutionResult::resolvedDispatching(41, 1, $utc);
$scheduled = DurableRetryInitialScheduleResolutionResult::resolvedScheduled(41, 1, $utc);
$coord = static fn (string $code, ?int $action, bool $intervention = false): DurableRetryCoordinationResult =>
    new DurableRetryCoordinationResult($code, 41, 1, $action, false, $intervention);
$sync = DurableRetryInitialSchedulingResult::synchronized($coord(DurableRetryCoordinationResult::SYNCHRONIZED_NEW, 501));
$already = DurableRetryInitialSchedulingResult::alreadySynchronized($coord(DurableRetryCoordinationResult::ALREADY_SYNCHRONIZED, 501));
$unavailable = DurableRetryInitialSchedulingResult::externalUnavailable($coord(DurableRetryCoordinationResult::EXTERNAL_UNAVAILABLE, null));
$failed = DurableRetryInitialSchedulingResult::coordinationFailed($coord(DurableRetryCoordinationResult::INELIGIBLE_STATE, null));
$uncertain = DurableRetryInitialSchedulingResult::coordinationUncertain($coord(DurableRetryCoordinationResult::EXTERNAL_ERROR, null, true));
$boom = new RuntimeException('dependency');

$cases = [
    ['F01', 7, $utc, $legacy, $dispatching, $sync, true, 'legacy_scheduled', [1,1,0,0]],
    ['F02', 8, $utc, $legacy, $dispatching, $sync, false, 'legacy_unavailable', [1,1,0,0]],
    ['F03', 9, $utc, $legacy, $dispatching, $sync, $boom, 'dependency_failure', [1,1,0,0]],
    ['F04', 10, $utc, $durable, $dispatching, $sync, false, 'durable_synchronized', [1,0,1,1]],
    ['F05', 11, $utc, $durable, $scheduled, $already, false, 'durable_already_synchronized', [1,0,1,1]],
    ['F06', 12, $utc, $durable, $dispatching, $unavailable, false, 'durable_external_unavailable', [1,0,1,1]],
    ['F07', 13, $utc, $durable, $dispatching, $failed, false, 'durable_coordination_failed', [1,0,1,1]],
    ['F08', 14, $utc, $durable, $dispatching, $uncertain, false, 'durable_coordination_uncertain', [1,0,1,1]],
    ['F09', 15, $utc, $durable, DurableRetryInitialScheduleResolutionResult::notFound(), $sync, false, 'resolution_failed', [1,0,1,0]],
    ['F10', 16, $utc, $durable, DurableRetryInitialScheduleResolutionResult::incompatible(), $sync, false, 'resolution_failed', [1,0,1,0]],
    ['F11', 17, $utc, $durable, DurableRetryInitialScheduleResolutionResult::readError(), $sync, false, 'resolution_failed', [1,0,1,0]],
    ['F12', 18, $utc, $closed, $dispatching, $sync, false, 'authority_closed', [1,0,0,0]],
    ['F13', 19, $utc, $closed, $dispatching, $sync, false, 'authority_closed', [1,0,0,0]],
    ['F14', 20, $utc, $closed, $dispatching, $sync, false, 'authority_closed', [1,0,0,0]],
    ['F15', 0, $utc, $legacy, $dispatching, $sync, true, 'invalid_input', [0,0,0,0]],
    ['F16', 21, new DateTimeImmutable('2030-01-02 03:04:05', new DateTimeZone('America/Santiago')), $legacy, $dispatching, $sync, true, 'invalid_input', [0,0,0,0]],
    ['F17', 22, $utc, $durable, $boom, $sync, false, 'dependency_failure', [1,0,1,0]],
    ['F18', 23, $utc, $durable, $dispatching, $boom, false, 'dependency_failure', [1,0,1,1]],
    ['F19', 24, $utc, $durable, $dispatching, $sync, false, 'durable_synchronized', [1,0,1,1]],
    ['F20', 25, $utc, $durable, $dispatching, $sync, false, 'durable_synchronized', [1,0,1,1]],
    ['F21', 26, $utc, $legacy, $dispatching, $sync, true, 'legacy_scheduled', [1,1,0,0]],
    ['F22', 27, $utc, $durable, $dispatching, $failed, false, 'durable_coordination_failed', [1,0,1,1]],
    ['F23', 28, $utc, $boom, $dispatching, $sync, false, 'dependency_failure', [1,0,0,0]],
    ['F24', 29, $utc, $durable, $boom, $sync, false, 'dependency_failure', [1,0,1,0]],
];

foreach ($cases as [$id, $reconciliationId, $date, $a5Outcome, $a6Outcome, $a7Outcome, $legacyOutcome, $state, $calls]) {
    $a5 = new A8AuthorityDouble($a5Outcome);
    $a6 = new A8ResolverDouble($a6Outcome);
    $a7 = new A8ScheduleDouble($a7Outcome);
    $legacyDouble = new A8LegacyDouble($legacyOutcome);
    $router = new DurableRetryInitialProductionRouter($a5, $a6, $a7, $legacyDouble);
    $result = $router->routeReconciliation($reconciliationId, $date);
    [$a5Calls, $legacyCalls, $a6Calls, $a7Calls] = $calls;
    $assert($result->state() === $state, "{$id}: state");
    $assert($result->reason() !== '', "{$id}: reason");
    $assert($result->reconciliationId() === ($state === 'invalid_input' ? 0 : $reconciliationId), "{$id}: identity");
    $assert($a5->calls === $a5Calls, "{$id}: A5 calls");
    $assert($legacyDouble->calls === $legacyCalls, "{$id}: legacy calls");
    $assert($a6->calls === $a6Calls, "{$id}: A6 calls");
    $assert($a7->calls === $a7Calls, "{$id}: A7 calls");
    $assert($a5->calls <= 1, "{$id}: one A5");
    $assert($legacyDouble->calls <= 1, "{$id}: one legacy");
    $assert($a6->calls <= 1, "{$id}: one A6");
    $assert($a7->calls <= 1, "{$id}: one A7");
    $assert(! ($legacyDouble->calls > 0 && ($a6->calls > 0 || $a7->calls > 0)), "{$id}: exclusive branch");
    $assert($result->legacyScheduledFlag() === ($state === 'legacy_scheduled'), "{$id}: legacy flag");
    $assert(! str_starts_with($state, 'durable_') || ! $result->permitsLegacy(), "{$id}: no durable fallback");
    $assert($a5Calls === 0 || $a5->request?->completionId() === $reconciliationId, "{$id}: canonical request");
}
if (count($cases) !== 24 || $assertions !== 360) { throw new RuntimeException("Unexpected functional total {$assertions}."); }
echo "durable retry initial production router: 24 cases, {$assertions} assertions\n";
