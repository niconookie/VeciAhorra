<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    'app/Modules/Orders/Contracts/DurableRetryExternalScheduleCoordinatorInterface.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryCoordinationResult.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryExternalScheduleCatalog.php',
    'app/Modules/Orders/Services/DurableRetryExternalScheduleCoordinator.php',
];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$source = '';
foreach ($paths as $path) {
    $content = file_get_contents($root . '/' . $path);
    $assert(is_string($content), "read {$path}");
    $source .= "\n" . $content;
}

foreach ([
    '$wpdb',
    'actionscheduler_',
    'SELECT ',
    'INSERT ',
    'UPDATE ',
    'DELETE ',
    'Repositories\\DurableRetryScheduleRepository',
    'ActionSchedulerDurableRetryAdapter',
    'as_schedule_',
    'as_get_scheduled_actions',
    'as_unschedule_',
    'add_action(',
    'add_filter(',
    'register_rest_route',
    'wp_schedule_event',
    'wp_schedule_single_event',
    'as_enqueue_async_action',
    'as_schedule_recurring_action',
    'as_unschedule_all_actions',
    'current_time(',
    'time()',
    'microtime(',
    "DateTimeImmutable('now",
    'error_log',
    'wp_enqueue_script',
    'Payment',
    'DeliveryCompletion',
    'FulfillmentCompletion',
    'BusinessCompletion',
] as $forbidden) {
    $assert(! str_contains($source, $forbidden), "forbids {$forbidden}");
}

$service = file_get_contents(
    $root . '/app/Modules/Orders/Services/DurableRetryExternalScheduleCoordinator.php'
);
$assert(
    substr_count($service, 'associateScheduledAction(') === 1,
    'uses only certified association CAS'
);
$assert(str_contains($service, 'private readonly Closure $utcNow'), 'explicit UTC clock');
$assert(
    str_contains($service, "'schedule_id' => \$snapshot->id()")
        && str_contains($service, "'generation' => \$snapshot->generation()"),
    'canonical minimal arguments'
);
$assert(
    ! str_contains($service, 'dispatch_token_hash')
        && ! str_contains($service, 'public_id')
        && ! str_contains($service, 'subject_id'),
    'no sensitive durable payload sent externally'
);
$assert(
    str_contains($service, 'if (! $created)')
        && str_contains($service, '$this->scheduler->cancel('),
    'compensation restricted to created action'
);
$assert(
    str_contains($service, '$snapshot->generation() !== $generation'),
    'stale generation closes before external work'
);
$assert(
    str_contains($service, 'DurableRetryStatus::DISPATCHING')
        && str_contains($service, 'DurableRetryStatus::SCHEDULED'),
    'closed eligible state handling'
);

$changed = [];
exec('git diff --name-only -- ' . implode(' ', $paths), $changed, $exit);
$assert($exit === 0, 'coordinator diff inspection succeeds');
$untracked = [];
exec(
    'git ls-files --others --exclude-standard -- ' . implode(' ', $paths),
    $untracked,
    $untrackedExit
);
$assert($untrackedExit === 0, 'coordinator untracked inspection succeeds');
$assert($changed === [] && $untracked === [], 'coordinator implementation unchanged');

echo "durable retry external schedule coordinator infrastructure: {$assertions} assertions\n";
