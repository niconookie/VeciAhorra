<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    'app/Modules/Orders/Contracts/DurableRetryExecutorInterface.php',
    'app/Modules/Orders/Contracts/DurableRetryStageProcessorInterface.php',
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
    'DurableRetryStageProcessorInterface',
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
$assert(substr_count($service, '$this->processor->process($context)') === 1, 'one processor call site');
$claimPosition = strpos($service, '$this->repository->transition($snapshot, $claimed)');
$processPosition = strpos($service, '$this->processor->process($context)');
$assert($claimPosition !== false && $claimPosition < $processPosition, 'claim precedes processing');
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
$assert(
    $exitCode === 0
        && $restrictedDiff === [
            'app/Modules/Delivery/Completion/Repository/DeliveryCompletionRepository.php',
            'app/Modules/Delivery/Completion/Service/DeliveryCompletionProcessor.php',
        ],
    'restricted diff limited to delivery completion authority seams'
);
$assert(str_contains(file_get_contents($root . '/app/Core/Config.php'), "SCHEMA_VERSION = '0.24.0'"), 'schema remains 0.24.0');

echo "durable retry executor infrastructure: {$assertions} assertions\n";
