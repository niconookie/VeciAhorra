<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
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
$assert($exit === 0 && $diff === [], 'restricted paths unchanged');

echo "durable retry action hook registrar infrastructure: {$assertions} assertions\n";
