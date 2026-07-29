<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$applicationPath = 'app/Core/Application.php';
$application = file_get_contents($root . '/' . $applicationPath);
$container = file_get_contents($root . '/app/Core/Container.php');
$executor = file_get_contents(
    $root . '/app/Modules/Orders/Services/DurableRetryExecutor.php'
);
$registry = file_get_contents(
    $root . '/app/Modules/Orders/Services/DurableRetryProcessorRegistry.php'
);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$assert(is_string($application), 'application readable');
$assert(is_string($container), 'container readable');
$assert(str_contains($application, 'private function registerDurableRetryGraph(): void'), 'dedicated composition method');
$assert(str_contains($application, 'public function durableRetryExecutor(): DurableRetryExecutor'), 'minimal executor API');
$assert(substr_count($application, 'new DurableRetryProcessorRegistry([') === 1, 'one registry construction');
$assert(substr_count($application, 'DurableRetryStageProcessorResolverInterface::class') === 2, 'resolver binding and injection');
$assert(substr_count($application, 'new DurableRetryExecutor(') === 1, 'one executor construction');
$assert(substr_count($application, 'new DurableRetryActionCallback(') === 1, 'one callback construction');
$assert(str_contains($application, 'public function durableRetryCallback(): DurableRetryActionCallback'), 'minimal callback API');
$assert(substr_count($application, 'new DurableRetryActionHookRegistrar(') === 1, 'one registrar construction');

foreach ([
    'new DurableRetryReconciliationProcessor(',
    'new DurableRetryBusinessCompletionProcessor(',
    'new DurableRetryDeliveryCompletionProcessor(',
    'new DurableRetryFulfillmentProcessor(',
] as $processor) {
    $assert(substr_count($application, $processor) === 1, "explicit {$processor}");
}

$methodStart = strpos($application, 'private function registerDurableRetryGraph(): void');
$methodEnd = strpos($application, 'private function registerPaymentGateway(): void');
$composition = substr($application, $methodStart, $methodEnd - $methodStart);
$assert(is_string($composition) && $composition !== '', 'composition method isolated');
foreach ([
    'add_action(', 'add_filter(', 'as_schedule_', 'as_cancel_',
    '->schedule(', '->coordinate(', '->execute(', '->process(', '->resolve(',
    'register_rest_route(', 'Reflection', 'class_exists(', 'switch (', 'match (',
    'sleep(', 'usleep(',
] as $forbidden) {
    $assert(! str_contains($composition, $forbidden), "composition excludes {$forbidden}");
}
$assert(! str_contains($executor, 'DurableRetryProcessorRegistry'), 'executor excludes concrete registry');
foreach ([
    'DurableRetryReconciliationProcessor',
    'DurableRetryBusinessCompletionProcessor',
    'DurableRetryDeliveryCompletionProcessor',
    'DurableRetryFulfillmentProcessor',
] as $processor) {
    $assert(! str_contains($registry, 'new ' . $processor), "registry does not construct {$processor}");
}
$assert(! str_contains($container, 'DurableRetry'), 'generic container remains domain agnostic');

$forbiddenPaths = [
    'app/Database',
    'app/Modules/Orders/Services/DurableRetryExecutor.php',
    'app/Modules/Orders/Services/DurableRetryProcessorRegistry.php',
    'app/Modules/Orders/Services/DurableRetryReconciliationProcessor.php',
    'app/Modules/Orders/Services/DurableRetryBusinessCompletionProcessor.php',
    'app/Modules/Orders/Services/DurableRetryDeliveryCompletionProcessor.php',
    'app/Modules/Orders/Services/DurableRetryFulfillmentProcessor.php',
    'app/Modules/Orders/Infrastructure/DurableRetry/ActionSchedulerDurableRetryAdapter.php',
    'app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryActionCallback.php',
    'app/Modules/Fulfillment/Orchestration',
    'docs',
];
exec(
    'git diff --name-only HEAD -- '
        . implode(' ', array_map('escapeshellarg', $forbiddenPaths)),
    $diff,
    $exit
);
$assert($exit === 0 && $diff === [], 'forbidden paths unchanged');

echo "durable retry composition infrastructure: {$assertions} assertions\n";
