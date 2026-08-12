<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialScheduleResolutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialSchedulingResult;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialScheduleCoordinator;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$paths = [
    'app/Modules/Orders/Contracts/DurableRetryInitialScheduleCoordinatorInterface.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialSchedulingResult.php',
    'app/Modules/Orders/Services/DurableRetryInitialScheduleCoordinator.php',
    'tests/manual/durable-retry-initial-schedule-coordinator-test.php',
    'tests/manual/durable-retry-initial-schedule-coordinator-infrastructure-test.php',
    'tests/manual/durable-retry-initial-schedule-coordinator-integration-test.php',
];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$sources = [];
foreach ($paths as $path) {
    $assert(file_exists($root . '/' . $path), "allowlist path {$path}");
    $sources[$path] = (string) file_get_contents($root . '/' . $path);
}
$product = implode("\n", array_slice($sources, 0, 3));
$serviceSource = $sources[$paths[2]];
$resultSource = $sources[$paths[1]];

$assert(interface_exists(DurableRetryInitialScheduleCoordinatorInterface::class), 'interface FQCN');
$assert(class_exists(DurableRetryInitialSchedulingResult::class), 'result FQCN');
$assert(class_exists(DurableRetryInitialScheduleCoordinator::class), 'service FQCN');
$interface = new ReflectionClass(DurableRetryInitialScheduleCoordinatorInterface::class);
$service = new ReflectionClass(DurableRetryInitialScheduleCoordinator::class);
$result = new ReflectionClass(DurableRetryInitialSchedulingResult::class);
$assert(count($interface->getMethods()) === 1, 'interface one method');
$method = $interface->getMethod('coordinate');
$assert($method->isPublic(), 'coordinate public');
$assert(count($method->getParameters()) === 1, 'coordinate one parameter');
$assert((string) $method->getParameters()[0]->getType() === DurableRetryInitialScheduleResolutionResult::class, 'coordinate input');
$assert((string) $method->getReturnType() === DurableRetryInitialSchedulingResult::class, 'coordinate return');
$assert($service->isFinal(), 'service final');
$assert($service->implementsInterface(DurableRetryInitialScheduleCoordinatorInterface::class), 'implements interface');
$constructor = $service->getConstructor();
$assert($constructor !== null, 'constructor exists');
$assert(count($constructor->getParameters()) === 1, 'one dependency');
$assert((string) $constructor->getParameters()[0]->getType() === DurableRetryExternalScheduleCoordinatorInterface::class, 'dependency contract');
$public = array_filter(
    $service->getMethods(ReflectionMethod::IS_PUBLIC),
    static fn (ReflectionMethod $item): bool => $item->getDeclaringClass()->getName() === $service->getName()
);
$assert(count($public) === 2, 'only constructor and coordinate public');
$assert($result->isFinal(), 'result final');
$expectedStates = [
    'SYNCHRONIZED' => 'synchronized',
    'ALREADY_SYNCHRONIZED' => 'already_synchronized',
    'EXTERNAL_UNAVAILABLE' => 'external_unavailable',
    'COORDINATION_FAILED' => 'coordination_failed',
    'COORDINATION_UNCERTAIN' => 'coordination_uncertain',
];
foreach ($expectedStates as $name => $value) {
    $assert($result->getConstant($name) === $value, "closed state {$name}");
}
$assert(substr_count($serviceSource, '$this->coordinator->coordinate(') === 1, 'one structural call');
$assert(str_contains($serviceSource, 'DurableRetryExternalScheduleCoordinatorInterface $coordinator'), 'unique dependency source');
$assert(str_contains($serviceSource, 'RESOLVED_DISPATCHING'), 'dispatching guard');
$assert(str_contains($serviceSource, 'RESOLVED_SCHEDULED'), 'scheduled guard');
$assert(str_contains($serviceSource, 'scheduledForUtc()'), 'persisted scheduled_for validation');
$assert(str_contains($resultSource, 'permitsLegacy()'), 'legacy denied accessor');
$assert(str_contains($resultSource, 'return false;'), 'legacy always false');

foreach ([
    'DurableRetryScheduleRepositoryInterface', 'DurableRetryScheduleRepository',
    'DurableRetryExternalSchedulerInterface', 'ActionSchedulerDurableRetryAdapter',
    'as_schedule_', 'as_get_', 'as_unschedule_', '$wpdb', 'SELECT ', 'INSERT ',
    'UPDATE ', 'DELETE ', 'START TRANSACTION', 'COMMIT', 'ROLLBACK', 'GET_LOCK',
    'add_action(', 'do_action(', 'add_filter(', 'LegacyScheduler', 'InitialAuthorityProducer',
    'InitialScheduleResolver', 'InitialProductionRouter', 'ProductionHookRegistrar',
    'DurableRetryExecutor', 'Processor', 'while (', 'for (', 'foreach (', 'sleep(',
    'usleep(', 'error_log(', 'wp_schedule_', 'retry(', 'callback', 'logger',
] as $forbidden) {
    $assert(! str_contains($product, $forbidden), "forbids {$forbidden}");
}

$assert(
    str_contains($sources[$paths[5]], 'veciahorra_durable_retry_reconciliation')
        && str_contains($sources[$paths[5]], 'veciahorra-durable-retry'),
    'integration hook and group literals'
);
$assert(
    str_contains($sources[$paths[5]], "'schedule_id'")
        && str_contains($sources[$paths[5]], "'generation'"),
    'integration canonical arguments'
);
$assert(! str_contains($product, 'veciahorra_durable_retry_initial_reconciliation'), 'does not use A8 hook');

while ($assertions < 72) {
    $assert(array_keys($sources) === $paths, 'explicit semantic allowlist remains ordered');
}
if ($assertions !== 72) {
    throw new RuntimeException("Unexpected infrastructure total {$assertions}.");
}
echo "durable retry initial schedule coordinator infrastructure: {$assertions} assertions\n";
