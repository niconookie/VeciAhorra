<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/tests/manual/support/durable-retry-head-delta-guard.php';
$a11LocalCoexistencePaths = ['app/Core/Application.php', 'app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php', 'app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php', 'tests/manual/durable-completion-orchestration-test.php', 'tests/manual/support/durable-retry-a11-coordinator.php', 'tests/manual/support/durable-retry-a11-runtime-capture-contract.php', 'tests/manual/durable-retry-a11-runtime-capture-test.php', 'tests/manual/durable-retry-a11-runtime-capture-infrastructure-test.php'];
$a11HistoricalMaintenancePaths = ['tests/manual/durable-retry-action-callback-infrastructure-test.php', 'tests/manual/durable-retry-action-hook-registrar-infrastructure-test.php', 'tests/manual/durable-retry-business-completion-processor-infrastructure-test.php', 'tests/manual/durable-retry-composition-infrastructure-test.php', 'tests/manual/durable-retry-delivery-completion-processor-infrastructure-test.php', 'tests/manual/durable-retry-executor-infrastructure-test.php', 'tests/manual/durable-retry-external-scheduler-infrastructure-test.php', 'tests/manual/durable-retry-initial-authority-producer-infrastructure-test.php', 'tests/manual/durable-retry-initial-transfer-authority-infrastructure-test.php', 'tests/manual/durable-retry-next-generation-infrastructure-test.php', 'tests/manual/durable-retry-processing-nullable-attempt-infrastructure-test.php', 'tests/manual/durable-retry-production-composition-infrastructure-test.php', 'tests/manual/durable-retry-reconciliation-processor-infrastructure-test.php'];
$normalizePaths = static fn (array $paths): array => array_values(array_unique(array_map(static fn (string $path): string => str_replace('\\', '/', $path), $paths)));
$a11AuthorizedExternalPaths = $normalizePaths(array_merge($a11LocalCoexistencePaths, $a11HistoricalMaintenancePaths));
$production = [
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryProcessingFailure.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryProcessingPolicy.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryProcessingResult.php',
    'app/Modules/Orders/Services/DurableRetryExecutor.php',
];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$sources = [];
foreach ($production as $path) {
    $source = file_get_contents($root . '/' . $path);
    $assert(is_string($source), "read {$path}");
    $sources[$path] = $source;
}
$all = implode("\n", $sources);
$failure = $sources[$production[0]];
$policy = $sources[$production[1]];
$result = $sources[$production[2]];
$executor = $sources[$production[3]];

$assert(str_contains($failure, 'private readonly ?int $confirmedAttemptNumber'), 'failure counter nullable');
$assert(str_contains($result, 'private readonly ?int $confirmedAttemptNumber'), 'result counter nullable');
$assert(substr_count($all, 'function hasConfirmedAttemptNumber(): bool') === 2, 'two closed presence APIs');
$assert(substr_count($all, 'function confirmedAttemptNumber(): ?int') === 2, 'two nullable accessors');
$assert(str_contains($failure, '$classification !== self::OUTCOME_UNCERTAIN'), 'failure null limited to uncertainty');
$assert(str_contains($result, '$classification !== DurableRetryProcessingFailure::OUTCOME_UNCERTAIN'), 'result null limited to uncertainty');
$assert(str_contains($executor, '$processing = DurableRetryProcessingResult::outcomeUncertain();'), 'exception maps to absent evidence');
$assert(! str_contains($executor, 'outcomeUncertain($expectedAttempt'), 'exception does not use expected attempt');
$assert(! str_contains($executor, 'outcomeUncertain($claimed'), 'exception does not use claimed state');
$assert(! str_contains($executor, 'outcomeUncertain($context'), 'exception does not use context');
$assert(str_contains($policy, '$confirmedAttempt !== $persistedAttempt + 1'), 'known evidence remains exact');
$assert(str_contains($policy, '$confirmedAttempt === null'), 'policy accepts explicit absence');

foreach (['= 0', '= -1', 'PHP_INT_MAX', "''"] as $sentinel) {
    $assert(! str_contains($failure . $result . $executor, '$confirmedAttemptNumber ' . $sentinel), "no counter sentinel {$sentinel}");
}
foreach ([
    '$wpdb',
    'SELECT ',
    'INSERT ',
    'UPDATE ',
    'DELETE ',
    'START TRANSACTION',
    'COMMIT',
    'ROLLBACK',
    'ActionScheduler',
    'as_schedule_',
    'add_action(',
    'add_filter(',
    'register_rest_route',
    'wp_schedule_',
    'current_time(',
    'wp_date(',
    'error_log',
    'getMessage(',
    'getTrace',
    'Modules\\Payments',
    'Modules\\Delivery',
    'Modules\\Fulfillment',
] as $forbidden) {
    $assert(! str_contains($all, $forbidden), "forbids {$forbidden}");
}

$policyInterface = file_get_contents($root . '/app/Modules/Orders/Contracts/DurableRetryProcessingPolicyInterface.php');
$executorInterface = file_get_contents($root . '/app/Modules/Orders/Contracts/DurableRetryExecutorInterface.php');
$processorInterface = file_get_contents($root . '/app/Modules/Orders/Contracts/DurableRetryStageProcessorInterface.php');
$assert(is_string($policyInterface) && substr_count($policyInterface, 'decideNextAttempt(') === 1, 'policy interface unchanged');
$assert(is_string($executorInterface) && substr_count($executorInterface, 'execute(') === 1, 'executor interface unchanged');
$assert(is_string($processorInterface) && substr_count($processorInterface, 'process(') === 1, 'processor interface unchanged');

$restricted = [
    'app/Core/Config.php',
    'app/Database',
    'app/Modules/Orders/Domain/DurableRetry',
    'app/Modules/Orders/Services',
    'app/Modules/Orders/Services/DurableRetryExecutor.php',
    'app/Modules/Orders/Repositories',
    'app/Modules/Orders/Infrastructure',
    'app/Modules/Payments',
    'app/Modules/Delivery',
    'app/Modules/Fulfillment',
    'docs',
    'artifacts',
];
exec(
    'git diff --name-only HEAD -- '
        . implode(' ', array_map('escapeshellarg', $restricted)),
    $diff,
    $exit
);
$diff = array_values(array_filter(
    $diff,
    static fn (string $path): bool =>
        $path !== 'app/Modules/Orders/Services/DurableRetryExecutor.php'
));
$diff = array_values(array_diff($normalizePaths($diff), $a11AuthorizedExternalPaths));
$diff = DurableRetryHeadDeltaGuard::unauthorizedPaths($root, $diff);
$assert($exit === 0, 'restricted diff inspection succeeds');
$assert($diff === [], 'restricted certified paths remain unchanged');
$assert(
    is_file($root . '/tests/manual/durable-retry-executor-nullable-attempt-test.php')
        && is_file($root . '/tests/manual/durable-retry-processing-nullable-attempt-test.php'),
    'new functional certifications present'
);
$assert(count(array_filter($diff, static fn (string $path): bool => str_starts_with($path, 'app/Modules/Orders/Domain/DurableRetry/'))) === 0, 'nullable domain remains unchanged');
$assert(count(array_filter($diff, static fn (string $path): bool => str_contains($path, '/Repository/'))) === 0, 'functional read authorities remain unchanged');
$assert(count(array_filter($diff, static fn (string $path): bool => str_contains($path, 'Coordinator'))) === 0, 'zero coordinators modified');
$assert(count(array_filter($diff, static fn (string $path): bool => str_contains($path, 'FulfillmentCompletion'))) === 0, 'fulfillment completion remains unchanged');
$assert(count(array_filter($diff, static fn (string $path): bool => str_starts_with($path, 'docs/'))) === 0, 'zero documents modified');
$assert(count(array_filter($diff, static fn (string $path): bool => str_starts_with($path, 'artifacts/'))) === 0, 'zero artifacts modified');
$assert(count(array_filter($diff, static fn (string $path): bool => str_contains($path, 'Config'))) === 0, 'zero config modified');
$assert(count(array_filter($diff, static fn (string $path): bool => str_contains($path, 'Schema'))) === 0, 'zero schema modified');

echo "durable retry nullable attempt infrastructure: {$assertions} assertions\n";
