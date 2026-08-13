<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$a11LocalCoexistencePaths = ['app/Core/Application.php', 'app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php', 'app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php', 'tests/manual/durable-completion-orchestration-test.php', 'tests/manual/support/durable-retry-a11-coordinator.php', 'tests/manual/support/durable-retry-a11-runtime-capture-contract.php', 'tests/manual/durable-retry-a11-runtime-capture-test.php', 'tests/manual/durable-retry-a11-runtime-capture-infrastructure-test.php'];
$a11HistoricalMaintenancePaths = ['tests/manual/durable-retry-action-callback-infrastructure-test.php', 'tests/manual/durable-retry-action-hook-registrar-infrastructure-test.php', 'tests/manual/durable-retry-business-completion-processor-infrastructure-test.php', 'tests/manual/durable-retry-composition-infrastructure-test.php', 'tests/manual/durable-retry-delivery-completion-processor-infrastructure-test.php', 'tests/manual/durable-retry-executor-infrastructure-test.php', 'tests/manual/durable-retry-external-scheduler-infrastructure-test.php', 'tests/manual/durable-retry-initial-authority-producer-infrastructure-test.php', 'tests/manual/durable-retry-initial-transfer-authority-infrastructure-test.php', 'tests/manual/durable-retry-next-generation-infrastructure-test.php', 'tests/manual/durable-retry-processing-nullable-attempt-infrastructure-test.php', 'tests/manual/durable-retry-production-composition-infrastructure-test.php', 'tests/manual/durable-retry-reconciliation-processor-infrastructure-test.php'];
$normalizePaths = static fn (array $paths): array => array_values(array_unique(array_map(static fn (string $path): string => str_replace('\\', '/', $path), $paths)));
$a11AuthorizedExternalPaths = $normalizePaths(array_merge($a11LocalCoexistencePaths, $a11HistoricalMaintenancePaths));
$adapter = file_get_contents(
    $root . '/app/Modules/Orders/Infrastructure/DurableRetry/'
        . 'ActionSchedulerDurableRetryAdapter.php'
);
$contract = file_get_contents(
    $root . '/app/Modules/Orders/Contracts/'
        . 'DurableRetryExternalSchedulerInterface.php'
);
$catalog = file_get_contents(
    $root . '/app/Modules/Orders/Domain/DurableRetry/'
        . 'DurableRetryExternalScheduleCatalog.php'
);
$source = $adapter . $contract . $catalog;
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'DurableRetryScheduleRepository',
    'durable_retry_schedules',
    '$wpdb',
    'actionscheduler_',
    'SELECT ',
    'INSERT ',
    'UPDATE ',
    'DELETE ',
    'add_action',
    'do_action',
    'wp_schedule_event',
    'wp_schedule_single_event',
    'as_schedule_recurring_action',
    'as_enqueue_async_action',
    'as_unschedule_all_actions',
    'register_rest_route',
    'error_log',
    'dispatch_token',
    'nonce',
    'WP_Error',
] as $forbidden) {
    $assert(! str_contains($source, $forbidden), "adapter excludes {$forbidden}");
}
$assert(substr_count($adapter, 'as_schedule_single_action') === 2, 'single scheduling API only');
$assert(substr_count($adapter, 'as_get_scheduled_actions') === 4, 'public pending query API only');
$assert(substr_count($adapter, 'as_unschedule_action') === 2, 'single identity cancellation API only');
$assert(str_contains($adapter, "'status' => 'pending'"), 'pending status is explicit');
$assert(str_contains($adapter, "'per_page' => 2"), 'duplicates are detectable');
$assert(str_contains($adapter, "'ids'"), 'provider objects never escape');
$assert(str_contains($adapter, 'true'), 'unique scheduling requested');
$assert(
    ! str_contains($catalog, 'veciahorra_process_'),
    'existing worker hooks are not reused with incompatible arguments'
);

$restricted = [
    'app/Core/Config.php',
    'app/Database',
    'app/Modules/Orders/Infrastructure/DurableRetry/ActionSchedulerDurableRetryAdapter.php',
    'app/Modules/Orders/Contracts/DurableRetryExternalSchedulerInterface.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryExternalScheduleCatalog.php',
    'app/Modules/Payments',
    'app/Modules/Delivery',
    'app/Modules/Fulfillment',
];
exec(
    'git diff --name-only HEAD -- '
        . implode(' ', array_map('escapeshellarg', $restricted)),
    $restrictedDiff,
    $exitCode
);
$restrictedDiff = array_values(array_diff($normalizePaths($restrictedDiff), $a11AuthorizedExternalPaths));
$assert(
    $exitCode === 0
        && $restrictedDiff === [],
    'restricted certified paths remain unchanged'
);

echo "durable retry external scheduler infrastructure: {$assertions} assertions\n";
