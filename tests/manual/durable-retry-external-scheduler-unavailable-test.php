<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleCatalog;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleResult;
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\ActionSchedulerDurableRetryAdapter;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$adapter = new ActionSchedulerDurableRetryAdapter();
$hook = DurableRetryExternalScheduleCatalog::RECONCILIATION;
$group = DurableRetryExternalScheduleCatalog::GROUP;
$args = ['schedule_id' => 1, 'generation' => 1];

$assert(
    ! function_exists('as_schedule_single_action')
        && ! function_exists('as_get_scheduled_actions')
        && ! function_exists('as_unschedule_action'),
    'provider functions are absent'
);
$assert(
    $adapter->schedule($hook, $args, $group, '2035-01-01 00:00:00')->code()
        === DurableRetryExternalScheduleResult::UNAVAILABLE,
    'schedule unavailable'
);
$assert(
    $adapter->findPending($hook, $args, $group)->code()
        === DurableRetryExternalScheduleResult::UNAVAILABLE,
    'find unavailable'
);
$assert(
    $adapter->cancel(1, $hook, $args, $group)->code()
        === DurableRetryExternalScheduleResult::UNAVAILABLE,
    'cancel unavailable'
);

echo "durable retry external scheduler unavailable: {$assertions} assertions\n";
