<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;

$root = dirname(__DIR__, 2);
require_once $root . '/app/Core/Config.php';
$a11LocalCoexistencePaths = ['app/Core/Application.php', 'app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php', 'app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php', 'tests/manual/durable-completion-orchestration-test.php', 'tests/manual/support/durable-retry-a11-coordinator.php', 'tests/manual/support/durable-retry-a11-runtime-capture-contract.php', 'tests/manual/durable-retry-a11-runtime-capture-test.php', 'tests/manual/durable-retry-a11-runtime-capture-infrastructure-test.php'];
$a11HistoricalMaintenancePaths = ['tests/manual/durable-retry-action-callback-infrastructure-test.php', 'tests/manual/durable-retry-action-hook-registrar-infrastructure-test.php', 'tests/manual/durable-retry-business-completion-processor-infrastructure-test.php', 'tests/manual/durable-retry-composition-infrastructure-test.php', 'tests/manual/durable-retry-delivery-completion-processor-infrastructure-test.php', 'tests/manual/durable-retry-executor-infrastructure-test.php', 'tests/manual/durable-retry-external-scheduler-infrastructure-test.php', 'tests/manual/durable-retry-initial-authority-producer-infrastructure-test.php', 'tests/manual/durable-retry-initial-transfer-authority-infrastructure-test.php', 'tests/manual/durable-retry-next-generation-infrastructure-test.php', 'tests/manual/durable-retry-processing-nullable-attempt-infrastructure-test.php', 'tests/manual/durable-retry-production-composition-infrastructure-test.php', 'tests/manual/durable-retry-reconciliation-processor-infrastructure-test.php'];
$normalizePaths = static fn (array $paths): array => array_values(array_unique(array_map(static fn (string $path): string => str_replace('\\', '/', $path), $paths)));
$a11AuthorizedExternalPaths = $normalizePaths(array_merge($a11LocalCoexistencePaths, $a11HistoricalMaintenancePaths));
$paths = [
    'app/Modules/Orders/Contracts/DurableRetryExecutorInterface.php',
    'app/Modules/Orders/Contracts/DurableRetryStageProcessorInterface.php',
    'app/Modules/Orders/Contracts/DurableRetryStageProcessorResolverInterface.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryExecutionContext.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryExecutionResult.php',
    'app/Modules/Orders/Services/DurableRetryExecutor.php',
];
$source = implode("\n", array_map(
    static fn (string $path): string => file_get_contents($root . '/' . $path),
    $paths
));
$service = file_get_contents($root . '/app/Modules/Orders/Services/DurableRetryExecutor.php');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) { throw new RuntimeException($message); }
};

foreach ($paths as $path) { $assert(is_file($root . '/' . $path), "exists {$path}"); }
$assert(str_contains($source, 'function execute('), 'executor contract');
$assert(str_contains($source, 'function stage(): string'), 'processor stage contract');
$assert(str_contains($source, 'function process('), 'processor process contract');
foreach ([
    'DurableRetryScheduleRepositoryInterface',
    'DurableRetryProcessingPolicyInterface',
    'DurableRetryExternalScheduleCoordinatorInterface',
    'DurableRetryStageProcessorResolverInterface',
    'Closure $utcNow',
] as $dependency) { $assert(str_contains($service, $dependency), "explicit dependency {$dependency}"); }
foreach ([
    '$wpdb', 'DurableRetryScheduleRepository ', 'ActionScheduler',
    'as_schedule_', 'as_get_scheduled_actions', 'findPending(',
    'add_action(', 'do_action(', 'register_rest_route', 'wp_enqueue_',
    'current_time(', 'wp_date(', 'time()', 'microtime(',
    "DateTimeImmutable('now", 'START TRANSACTION', 'COMMIT', 'ROLLBACK',
    'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'error_log(', 'trigger_error(',
    'Modules\\Payments', 'Modules\\Delivery', 'Modules\\Fulfillment',
] as $forbidden) { $assert(! str_contains($source, $forbidden), "excludes {$forbidden}"); }
$assert(substr_count($service, '$this->processorResolver->resolve($snapshot->stage())') === 1, 'one resolver call site');
$assert(substr_count($service, '$processor->process($context)') === 1, 'one processor call site');
$resolvePosition = strpos($service, '$this->processorResolver->resolve($snapshot->stage())');
$claimPosition = strpos($service, '$this->repository->transition($snapshot, $claimed)');
$processPosition = strpos($service, '$processor->process($context)');
$assert($resolvePosition !== false && $resolvePosition < $claimPosition && $claimPosition < $processPosition, 'resolve precedes claim and processing');
$assert(! str_contains($service, 'DurableRetryProcessorRegistry'), 'executor excludes concrete registry');
foreach (['DurableRetryReconciliationProcessor', 'DurableRetryBusinessCompletionProcessor', 'DurableRetryDeliveryCompletionProcessor', 'DurableRetryFulfillmentProcessor', 'ReflectionClass', 'switch (', 'sleep(', 'usleep('] as $forbiddenDispatch) {
    $assert(! str_contains($service, $forbiddenDispatch), "executor excludes {$forbiddenDispatch}");
}
$assert(substr_count($service, '$this->policy->decideNextAttempt(') === 1, 'one policy call site');
$assert(substr_count($service, '$this->coordinator->coordinate(') === 1, 'one coordinator call site');
$assert(str_contains($service, '$successor->id()') && str_contains($service, '$successor->generation()'), 'coordinates successor identity');
$assert(
    ! preg_match('/coordinator->coordinate\\(\\s*\\$claimed->id\\(\\)/', $service),
    'never coordinates historical ID'
);
$assert(str_contains($service, 'supersedeAndCreateNextGeneration($claimed, $decision, $decidedAt)'), 'certified succession only');
$assert(str_contains($service, 'DurableRetryExternalScheduleCatalog::hooks()'), 'closed hook allowlist');
$assert(str_contains($service, 'hookForStage($snapshot->stage())'), 'hook derives durable stage');
$assert(
    str_contains($service, '$confirmedAttempt !== $expectedAttempt')
        && str_contains($service, '$processing->confirmedAttemptNumber()'),
    'confirmed attempt validated'
);

$restricted = [
    'app/Core/Config.php', 'app/Database', 'app/Modules/Orders/Repositories',
    'app/Modules/Orders/Services/DurableRetryExternalScheduleCoordinator.php',
    'app/Modules/Orders/Infrastructure', 'app/Modules/Payments',
    'app/Modules/Delivery', 'app/Modules/Fulfillment', 'docs', 'artifacts',
];
exec(
    'git diff --name-only HEAD -- ' . implode(' ', array_map('escapeshellarg', $restricted)),
    $restrictedDiff,
    $exitCode
);
$restrictedDiff = array_values(array_diff($normalizePaths($restrictedDiff), $a11AuthorizedExternalPaths));
$assert(
    $exitCode === 0
        && $restrictedDiff === [],
    'restricted certified paths remain unchanged'
);
$assert(
    is_string(Config::SCHEMA_VERSION)
        && version_compare(Config::SCHEMA_VERSION, '0.24.0', '>='),
    'schema remains compatible with 0.24.0'
);

echo "durable retry executor infrastructure: {$assertions} assertions\n";
