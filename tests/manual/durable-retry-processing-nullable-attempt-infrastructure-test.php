<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
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

exec('git diff --name-only HEAD', $diff, $exit);
$assert($exit === 0, 'diff allowlist inspection succeeds');
$allowed = array_merge($production, [
    'tests/manual/durable-retry-executor-infrastructure-test.php',
    'tests/manual/durable-retry-next-generation-infrastructure-test.php',
    'tests/manual/durable-retry-processing-policy-infrastructure-test.php',
]);
sort($allowed);
sort($diff);
$assert($diff === $allowed, 'exact tracked nullable attempt diff allowlist');
$assert(
    is_file($root . '/tests/manual/durable-retry-executor-nullable-attempt-test.php')
        && is_file($root . '/tests/manual/durable-retry-processing-nullable-attempt-test.php'),
    'new functional certifications present'
);
$assert(count(array_filter($diff, static fn (string $path): bool => str_starts_with($path, 'app/Modules/Orders/Domain/DurableRetry/'))) === 3, 'exactly three domain files modified');
$assert(count(array_filter($diff, static fn (string $path): bool => str_starts_with($path, 'app/Modules/Orders/Services/'))) === 1, 'exactly one service modified');
$assert(count(array_filter($diff, static fn (string $path): bool => str_contains($path, 'Repository'))) === 0, 'zero repositories modified');
$assert(count(array_filter($diff, static fn (string $path): bool => str_contains($path, 'Coordinator'))) === 0, 'zero coordinators modified');
$assert(count(array_filter($diff, static fn (string $path): bool => str_contains($path, 'Reconciliation'))) === 0, 'zero reconciliation modified');
$assert(count(array_filter($diff, static fn (string $path): bool => str_starts_with($path, 'docs/'))) === 0, 'zero documents modified');
$assert(count(array_filter($diff, static fn (string $path): bool => str_starts_with($path, 'artifacts/'))) === 0, 'zero artifacts modified');
$assert(count(array_filter($diff, static fn (string $path): bool => str_contains($path, 'Config'))) === 0, 'zero config modified');
$assert(count(array_filter($diff, static fn (string $path): bool => str_contains($path, 'Schema'))) === 0, 'zero schema modified');

echo "durable retry nullable attempt infrastructure: {$assertions} assertions\n";
