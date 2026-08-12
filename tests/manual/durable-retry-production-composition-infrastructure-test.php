<?php

declare(strict_types=1);

if (! class_exists('wpdb')) {
    class wpdb { public string $prefix = 'wp_'; }
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationConfigurationValueReaderInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalSchedulerInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacySchedulerInterface;
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryProductionComposition;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialProductionRouter;

$root = dirname(__DIR__, 2);
$allowlist = [
    'app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryProductionComposition.php',
    'app/Modules/Fulfillment/Orchestration/DurableCompletionScheduler.php',
    'tests/manual/durable-retry-production-composition-test.php',
    'tests/manual/durable-retry-production-composition-infrastructure-test.php',
    'tests/manual/durable-retry-production-composition-integration-test.php',
];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) { throw new RuntimeException($message); }
};
foreach ($allowlist as $path) {
    $assert(is_file($root . '/' . $path), "allowlist path {$path}");
}

$matches = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app')) as $file) {
    if ($file->isFile() && str_contains($file->getFilename(), 'DurableRetryProductionComposition')) {
        $matches[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    }
}
$assert($matches === [$allowlist[0]], 'no sixth A9 composition product');

$class = new ReflectionClass(DurableRetryProductionComposition::class);
$assert($class->isFinal(), 'composition final');
$assert($class->getName() === DurableRetryProductionComposition::class, 'exact FQCN');
$constructor = $class->getConstructor();
$assert($constructor !== null && $constructor->isPublic(), 'public constructor');
$expected = [
    'wpdb',
    DurableRetryActivationConfigurationValueReaderInterface::class,
    DurableRetryExternalSchedulerInterface::class,
    DurableRetryLegacySchedulerInterface::class,
    Closure::class,
];
$assert(count($constructor->getParameters()) === 5, 'five constructor dependencies');
foreach ($constructor->getParameters() as $index => $parameter) {
    $assert((string) $parameter->getType() === $expected[$index], "constructor dependency {$index}");
}
$router = $class->getMethod('router');
$assert($router->isPublic(), 'router public');
$assert($router->getParameters() === [], 'router no arguments');
$assert((string) $router->getReturnType() === DurableRetryInitialProductionRouter::class, 'router exact return');
$public = array_filter(
    $class->getMethods(ReflectionMethod::IS_PUBLIC),
    static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class->getName()
);
$assert(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $public) === ['__construct', 'router'], 'closed public API');
$assert(array_filter($class->getProperties(), static fn (ReflectionProperty $property): bool => $property->isStatic()) === [], 'no static state');

$source = (string) file_get_contents($root . '/' . $allowlist[0]);
foreach ([
    'add_action', 'add_filter', 'do_action', 'apply_filters', 'as_schedule_',
    'as_has_', 'as_get_', 'as_unschedule_', 'global ', '$GLOBALS',
    'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'START TRANSACTION',
    'get_option(', 'update_option(', 'routeReconciliation(',
    'produceReconciliation(', '->resolve(', '->coordinate(', 'sleep(',
    'usleep(', 'Reflection', 'new $', 'Container', '->make(', '->bind(',
    '->singleton(', 'WebpayReconciliationMaterializer', 'add_callback',
] as $forbidden) {
    $assert(! str_contains($source, $forbidden), "composition forbids {$forbidden}");
}
$assert(substr_count($source, 'new DurableRetryInitialProductionRouter(') === 1, 'one product router');
$assert(substr_count($source, 'catch (Throwable $error)') === 1, 'one atomic failure boundary');
$assert(str_contains($source, 'throw $error;'), 'same error propagated');
$assert(str_contains($source, 'private ?DurableRetryInitialProductionRouter $composedRouter = null;'), 'nullable unpublished state');

$gitChanges = [];
exec('git -C ' . escapeshellarg($root) . ' status --short --untracked-files=all', $gitChanges, $gitExit);
$assert($gitExit === 0, 'git status available');
$a9Changes = [];
$changedProductTests = [];
foreach ($gitChanges as $line) {
    $path = str_replace('\\', '/', substr($line, 3));
    if (in_array($path, $allowlist, true)) {
        $a9Changes[] = $path;
    }
    if (str_starts_with($path, 'app/') || str_starts_with($path, 'tests/')) {
        $changedProductTests[] = $path;
    }
}
$assert(array_diff($changedProductTests, $allowlist) === [], 'changed product/test paths stay in A9 allowlist');
sort($a9Changes);
$expectedChanged = $allowlist;
sort($expectedChanged);
$trackedA9 = [];
exec(
    'git -C ' . escapeshellarg($root) . ' ls-files -- '
        . implode(' ', array_map('escapeshellarg', $allowlist)),
    $trackedA9,
    $trackedExit
);
sort($trackedA9);
$assert(
    $a9Changes === $expectedChanged
        || ($a9Changes === [] && $trackedExit === 0 && $trackedA9 === $expectedChanged),
    'all and only A9 paths changed or represented by certified snapshot'
);
$assert(exec('git -C ' . escapeshellarg($root) . ' diff --name-only -- app/Core/Application.php') === '', 'bootstrap untouched');
$assert(exec('git -C ' . escapeshellarg($root) . ' diff --name-only -- app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php') === '', 'materializer untouched');

$result = new ReflectionClass(VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialProductionRoutingResult::class);
$stateNames = [
    'LEGACY_SCHEDULED', 'LEGACY_UNAVAILABLE', 'DURABLE_SYNCHRONIZED',
    'DURABLE_ALREADY_SYNCHRONIZED', 'DURABLE_EXTERNAL_UNAVAILABLE',
    'DURABLE_COORDINATION_FAILED', 'DURABLE_COORDINATION_UNCERTAIN',
    'AUTHORITY_CLOSED', 'RESOLUTION_FAILED', 'INVALID_INPUT',
    'DEPENDENCY_FAILURE',
];
$assert(count(array_intersect_key($result->getConstants(), array_flip($stateNames))) === 11, 'eleven A8 states');

echo "durable retry production composition infrastructure: {$assertions} assertions\n";
