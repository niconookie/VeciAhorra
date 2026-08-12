<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = 'app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryActionCallback.php';
$callback = file_get_contents($root . '/' . $path);
$application = file_get_contents($root . '/app/Core/Application.php');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) { throw new RuntimeException($message); }
};

$assert(is_string($callback), 'callback readable');
$assert(str_contains($callback, 'final class DurableRetryActionCallback'), 'callback final');
$assert(str_contains($callback, 'private readonly DurableRetryExecutorInterface $executor'), 'executor contract injected');
$assert(substr_count($callback, '$this->executor->execute(') === 1, 'one executor call site');
$assert(str_contains($callback, 'DurableRetryExternalScheduleCatalog::normalizeIdentity('), 'canonical identity authority');
$assert(str_contains($callback, 'DurableRetryExternalScheduleCatalog::GROUP'), 'canonical group authority');
$assert(substr_count($callback, 'Invalid durable retry callback invocation.') === 2, 'closed safe message');

foreach ([
    'DurableRetryProcessorRegistry', 'DurableRetryStageProcessor',
    'Repository', 'Scheduler', 'ActionScheduler', 'Container', '$GLOBALS',
    'global ', '$wpdb', 'add_action(', 'add_filter(', 'as_',
    'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'sleep(', 'usleep(',
    'while (', 'for (', 'catch (Throwable', '->process(', '->resolve(',
    '->coordinate(', 'backoff', 'terminal',
] as $forbidden) {
    $assert(! str_contains($callback, $forbidden), "callback excludes {$forbidden}");
}
foreach ([
    'reconciliation', 'business_completion', 'delivery_completion',
    'fulfillment_completion',
] as $stage) {
    $assert(! str_contains($callback, $stage), "callback excludes stage {$stage}");
}

$assert(substr_count($application, 'new DurableRetryActionCallback(') === 1, 'application constructs callback once');
$assert(str_contains($application, 'public function durableRetryCallback(): DurableRetryActionCallback'), 'minimal callback API');
$assert(str_contains($application, '$this->container->make(DurableRetryExecutor::class)'), 'callback reuses executor binding');

$methodStart = strpos($application, 'private function registerDurableRetryGraph(): void');
$methodEnd = strpos($application, 'private function registerPaymentGateway(): void');
$composition = substr($application, $methodStart, $methodEnd - $methodStart);
$assert(! str_contains($composition, '->execute('), 'composition never invokes callback or executor');
$assert(! str_contains($composition, 'add_action('), 'composition registers no hook');
$assert(! str_contains($composition, 'as_schedule_'), 'composition schedules nothing');

$restricted = [
    'app/Core/Bootstrap.php', 'app/Database',
    'app/Modules/Orders/Services/DurableRetryExecutor.php',
    'app/Modules/Orders/Services/DurableRetryProcessorRegistry.php',
    'app/Modules/Orders/Services/DurableRetryReconciliationProcessor.php',
    'app/Modules/Orders/Services/DurableRetryBusinessCompletionProcessor.php',
    'app/Modules/Orders/Services/DurableRetryDeliveryCompletionProcessor.php',
    'app/Modules/Orders/Services/DurableRetryFulfillmentProcessor.php',
    'app/Modules/Orders/Infrastructure/DurableRetry/ActionSchedulerDurableRetryAdapter.php',
    'app/Modules/Fulfillment/Orchestration', 'docs',
];
exec(
    'git diff --name-only HEAD -- '
        . implode(' ', array_map('escapeshellarg', $restricted)),
    $diff,
    $exit
);
$assert($exit === 0 && $diff === [], 'restricted paths unchanged');

echo "durable retry action callback infrastructure: {$assertions} assertions\n";
