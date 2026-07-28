<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
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
    'app/Modules/Orders/Repositories/DurableRetryScheduleRepository.php',
    'app/Modules/Orders/Contracts/DurableRetryScheduleRepositoryInterface.php',
    'app/Modules/Payments',
    'app/Modules/Delivery',
    'app/Modules/Fulfillment',
];
exec(
    'git diff --name-only 2b9b2e6fefc44881dbbf99747dc2cdbd755a881e -- '
        . implode(' ', array_map('escapeshellarg', $restricted)),
    $restrictedDiff,
    $exitCode
);
$assert($exitCode === 0 && $restrictedDiff === [], 'restricted architecture remains unchanged');

echo "durable retry external scheduler infrastructure: {$assertions} assertions\n";
