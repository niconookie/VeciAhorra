<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$a11LocalCoexistencePaths = ['app/Core/Application.php', 'app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php', 'app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php', 'tests/manual/durable-completion-orchestration-test.php', 'tests/manual/support/durable-retry-a11-coordinator.php', 'tests/manual/support/durable-retry-a11-runtime-capture-contract.php', 'tests/manual/durable-retry-a11-runtime-capture-test.php', 'tests/manual/durable-retry-a11-runtime-capture-infrastructure-test.php'];
$a11HistoricalMaintenancePaths = ['tests/manual/durable-retry-action-callback-infrastructure-test.php', 'tests/manual/durable-retry-action-hook-registrar-infrastructure-test.php', 'tests/manual/durable-retry-business-completion-processor-infrastructure-test.php', 'tests/manual/durable-retry-composition-infrastructure-test.php', 'tests/manual/durable-retry-delivery-completion-processor-infrastructure-test.php', 'tests/manual/durable-retry-executor-infrastructure-test.php', 'tests/manual/durable-retry-external-scheduler-infrastructure-test.php', 'tests/manual/durable-retry-initial-authority-producer-infrastructure-test.php', 'tests/manual/durable-retry-initial-transfer-authority-infrastructure-test.php', 'tests/manual/durable-retry-next-generation-infrastructure-test.php', 'tests/manual/durable-retry-processing-nullable-attempt-infrastructure-test.php', 'tests/manual/durable-retry-production-composition-infrastructure-test.php', 'tests/manual/durable-retry-reconciliation-processor-infrastructure-test.php'];
$normalizePaths = static fn (array $paths): array => array_values(array_unique(array_map(static fn (string $path): string => str_replace('\\', '/', $path), $paths)));
$a11AuthorizedExternalPaths = $normalizePaths(array_merge($a11LocalCoexistencePaths, $a11HistoricalMaintenancePaths));
$path = 'app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryActionHookRegistrar.php';
$registrar = file_get_contents($root . '/' . $path);
$application = file_get_contents($root . '/app/Core/Application.php');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) { throw new RuntimeException($message); }
};

$assert(is_string($registrar), 'registrar readable');
$assert(str_contains($registrar, 'final class DurableRetryActionHookRegistrar'), 'registrar final');
$assert(str_contains($registrar, 'private readonly DurableRetryActionCallback $callback'), 'callback injected');
$assert(str_contains($registrar, 'private bool $registered = false'), 'instance idempotency');
$assert(substr_count($registrar, 'add_action(') === 1, 'single registration call site');
$assert(str_contains($registrar, 'DurableRetryExternalScheduleCatalog::hooks()'), 'canonical hook authority');
$assert(str_contains($registrar, 'private const PRIORITY = 10'), 'priority ten');
$assert(str_contains($registrar, 'private const ACCEPTED_ARGUMENTS = 2'), 'exactly two arguments');
$assert(substr_count($registrar, '$callback->execute($hook, $scheduleId, $generation)') === 1, 'single delegation site');

foreach ([
    'DurableRetryExecutor', 'DurableRetryProcessorRegistry',
    'DurableRetryReconciliationProcessor', 'DurableRetryBusinessCompletionProcessor',
    'DurableRetryDeliveryCompletionProcessor', 'DurableRetryFulfillmentProcessor',
    'Repository', 'Policy', 'Scheduler', '$wpdb', '$GLOBALS', 'global ',
    'add_filter(', 'do_action(', 'as_', 'wp_schedule_', 'SELECT ', 'INSERT ',
    'UPDATE ', 'DELETE ', 'sleep(', 'usleep(', 'Reflection', 'Container',
    '->resolve(', '->process(', '->coordinate(', 'catch (',
] as $forbidden) {
    $assert(! str_contains($registrar, $forbidden), "registrar excludes {$forbidden}");
}
foreach ([
    'reconciliation', 'business_completion', 'delivery_completion',
    'fulfillment_completion',
] as $stage) {
    $assert(! str_contains($registrar, $stage), "registrar excludes stage {$stage}");
}

$assert(substr_count($application, 'new DurableRetryActionHookRegistrar(') === 1, 'application composes registrar once');
$assert(substr_count($application, 'DurableRetryActionHookRegistrar::class') === 2, 'binding and one startup resolution');
$runStart = strpos($application, 'public function run(): void');
$containerStart = strpos($application, 'public function container(): Container');
$run = substr($application, $runStart, $containerStart - $runStart);
$assert(substr_count($run, 'DurableRetryActionHookRegistrar::class') === 1, 'startup registers durable hooks once');
$assert(substr_count($run, '->register();') >= 1, 'startup invokes registrar');

$restricted = [
    'app/Core/Bootstrap.php', 'app/Database',
    'app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryActionCallback.php',
    'app/Modules/Orders/Infrastructure/DurableRetry/ActionSchedulerDurableRetryAdapter.php',
    'app/Modules/Orders/Services', 'app/Modules/Fulfillment/Orchestration',
    'docs',
];
exec(
    'git diff --name-only HEAD -- '
        . implode(' ', array_map('escapeshellarg', $restricted)),
    $diff,
    $exit
);
$diff = array_values(array_diff($normalizePaths($diff), $a11AuthorizedExternalPaths));
$assert($exit === 0 && $diff === [], 'restricted paths unchanged');

echo "durable retry action hook registrar infrastructure: {$assertions} assertions\n";
