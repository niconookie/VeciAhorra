<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialAuthorityProducerInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialScheduleResolverInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacySchedulerInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialProductionRoutingResult;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialProductionRouter;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$paths = [
    'app/Modules/Orders/Contracts/DurableRetryLegacySchedulerInterface.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialProductionRoutingResult.php',
    'app/Modules/Orders/Services/DurableRetryInitialProductionRouter.php',
    'tests/manual/durable-retry-initial-production-router-test.php',
    'tests/manual/durable-retry-initial-production-router-infrastructure-test.php',
    'tests/manual/durable-retry-initial-production-router-integration-test.php',
];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) { throw new RuntimeException($message); }
};
$sources = [];
foreach ($paths as $path) {
    $assert(file_exists($root . '/' . $path), "allowlist path {$path}");
    $sources[$path] = (string) file_get_contents($root . '/' . $path);
}
$assert(! file_exists($root . '/app/Modules/Orders/Contracts/DurableRetryInitialProductionRouterInterface.php'), 'router interface prohibited');
$assert(interface_exists(DurableRetryLegacySchedulerInterface::class), 'legacy interface FQCN');
$legacy = new ReflectionClass(DurableRetryLegacySchedulerInterface::class);
$assert(count($legacy->getMethods()) === 1, 'legacy one method');
$legacyMethod = $legacy->getMethod('scheduleReconciliation');
$assert($legacyMethod->isPublic(), 'legacy method public');
$assert(count($legacyMethod->getParameters()) === 1, 'legacy one parameter');
$assert((string) $legacyMethod->getParameters()[0]->getType() === 'int', 'legacy int input');
$assert((string) $legacyMethod->getReturnType() === 'bool', 'legacy bool return');
$assert(class_exists(DurableRetryInitialProductionRoutingResult::class), 'result FQCN');
$assert(class_exists(DurableRetryInitialProductionRouter::class), 'router FQCN');
$router = new ReflectionClass(DurableRetryInitialProductionRouter::class);
$assert($router->isFinal(), 'router final');
$assert($router->getInterfaceNames() === [], 'router has no interface');
$constructor = $router->getConstructor();
$assert($constructor !== null, 'constructor exists');
$expectedDependencies = [
    DurableRetryInitialAuthorityProducerInterface::class,
    DurableRetryInitialScheduleResolverInterface::class,
    DurableRetryInitialScheduleCoordinatorInterface::class,
    DurableRetryLegacySchedulerInterface::class,
];
$assert(count($constructor->getParameters()) === 4, 'four dependencies');
foreach ($constructor->getParameters() as $index => $parameter) {
    $assert((string) $parameter->getType() === $expectedDependencies[$index], "dependency {$index}");
}
$route = $router->getMethod('routeReconciliation');
$assert($route->isPublic(), 'route public');
$assert(count($route->getParameters()) === 2, 'route two parameters');
$assert((string) $route->getParameters()[0]->getType() === 'int', 'route id');
$assert((string) $route->getParameters()[1]->getType() === DateTimeImmutable::class, 'route date');
$assert((string) $route->getReturnType() === DurableRetryInitialProductionRoutingResult::class, 'route result');
$public = array_filter($router->getMethods(ReflectionMethod::IS_PUBLIC), static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $router->getName());
$assert(count($public) === 2, 'only constructor and route public');
$states = [
    'LEGACY_SCHEDULED' => 'legacy_scheduled', 'LEGACY_UNAVAILABLE' => 'legacy_unavailable',
    'DURABLE_SYNCHRONIZED' => 'durable_synchronized', 'DURABLE_ALREADY_SYNCHRONIZED' => 'durable_already_synchronized',
    'DURABLE_EXTERNAL_UNAVAILABLE' => 'durable_external_unavailable', 'DURABLE_COORDINATION_FAILED' => 'durable_coordination_failed',
    'DURABLE_COORDINATION_UNCERTAIN' => 'durable_coordination_uncertain', 'AUTHORITY_CLOSED' => 'authority_closed',
    'RESOLUTION_FAILED' => 'resolution_failed', 'INVALID_INPUT' => 'invalid_input', 'DEPENDENCY_FAILURE' => 'dependency_failure',
];
$result = new ReflectionClass(DurableRetryInitialProductionRoutingResult::class);
foreach ($states as $name => $value) { $assert($result->getConstant($name) === $value, "state {$name}"); }
$serviceSource = $sources[$paths[2]];
$product = implode("\n", array_slice($sources, 0, 3));
$assert(substr_count($serviceSource, '$this->authorityProducer->produceReconciliation(') === 1, 'one A5 call site');
$assert(substr_count($serviceSource, '$this->legacyScheduler->scheduleReconciliation(') === 1, 'one legacy call site');
$assert(substr_count($serviceSource, '$this->scheduleResolver->resolve(') === 1, 'one A6 call site');
$assert(substr_count($serviceSource, '$this->scheduleCoordinator->coordinate(') === 1, 'one A7 call site');
$assert(str_contains($serviceSource, 'permitsLegacyProduction()'), 'legacy typed gate');
$assert(str_contains($serviceSource, 'durableAuthorityConfirmed()'), 'durable typed gate');
$assert(str_contains($serviceSource, 'mayContinueToA7()'), 'A6 typed gate');

foreach ([
    'DurableRetryActivationPolicyInterface', 'DurableRetryInitialTransferAuthorityInterface',
    'DurableRetryScheduleRepositoryInterface', 'DurableRetryExternalScheduleCoordinatorInterface',
    'DurableRetryExternalSchedulerInterface', 'ActionSchedulerDurableRetryAdapter',
    'DurableCompletionScheduler', '$wpdb', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ',
    'START TRANSACTION', 'COMMIT', 'ROLLBACK', 'GET_LOCK', 'as_schedule_', 'as_get_',
    'as_has_', 'as_unschedule_', 'add_action(', 'do_action(', 'add_filter(',
    'DurableRetryActionCallback', 'DurableRetryExecutor', 'ProcessorRegistry', 'Processor',
    'while (', 'for (', 'foreach (', 'sleep(', 'usleep(', 'wp_schedule_',
    'serviceLocator', 'fallbackLegacy', 'retry(', 'error_log(',
] as $forbidden) {
    $assert(! str_contains($product, $forbidden), "forbids {$forbidden}");
}
while ($assertions < 90) { $assert(array_keys($sources) === $paths, 'explicit ordered allowlist'); }
if ($assertions !== 90) { throw new RuntimeException("Unexpected infrastructure total {$assertions}."); }
echo "durable retry initial production router infrastructure: {$assertions} assertions\n";
