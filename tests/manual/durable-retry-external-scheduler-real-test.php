<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleCatalog;

require_once dirname(__DIR__, 5) . '/wp-load.php';

$required = [
    'as_schedule_single_action',
    'as_get_scheduled_actions',
    'as_unschedule_action',
];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
foreach ($required as $function) {
    $assert(function_exists($function), "{$function} is available");
}
$assert(
    DurableRetryExternalScheduleCatalog::timestamp('2035-01-01 00:00:00')
        === 2051222400,
    'canonical UTC is independent from WordPress timezone'
);

$hook = 'veciahorra_durable_retry_adapter_contract_test';
$group = 'veciahorra-durable-retry-contract-test';
$args = [
    'schedule_id' => random_int(1_000_000_000, 1_500_000_000),
    'generation' => random_int(100_000, 900_000),
];
$timestamp = time() + (366 * DAY_IN_SECONDS);
$actionId = null;
$scheduleReturnType = null;
$findReturnType = null;
$cancelReturnType = null;

try {
    $assert(has_action($hook) === false, 'exclusive real hook has no callback');
    $actionId = as_schedule_single_action(
        $timestamp,
        $hook,
        $args,
        $group,
        true,
        10
    );
    $scheduleReturnType = get_debug_type($actionId);
    $assert(is_int($actionId) && $actionId > 0, 'real scheduling returns positive int');

    $ids = as_get_scheduled_actions([
        'hook' => $hook,
        'args' => $args,
        'group' => $group,
        'status' => 'pending',
        'per_page' => 2,
        'orderby' => 'date',
        'order' => 'ASC',
    ], 'ids');
    $assert(is_array($ids) && count($ids) === 1, 'real pending query returns one ID');
    $rawFoundId = array_values($ids)[0];
    $findReturnType = get_debug_type($rawFoundId);
    $assert(
        (string) $rawFoundId === (string) $actionId,
        'real pending ID matches scheduled ID'
    );

    $objects = as_get_scheduled_actions([
        'hook' => $hook,
        'args' => $args,
        'group' => $group,
        'status' => 'pending',
        'per_page' => 2,
    ], OBJECT);
    $assert(
        is_array($objects)
            && isset($objects[$actionId])
            && $objects[$actionId]->get_hook() === $hook
            && $objects[$actionId]->get_args() === $args
            && $objects[$actionId]->get_group() === $group,
        'real hook arguments and group are exact'
    );
    $date = $objects[$actionId]->get_schedule()->get_date();
    $assert(
        $date !== null && $date->getTimestamp() === $timestamp,
        'real scheduled timestamp is exact'
    );

    $cancelledId = as_unschedule_action($hook, $args, $group);
    $cancelReturnType = get_debug_type($cancelledId);
    $assert(
        (string) $cancelledId === (string) $actionId,
        'real cancellation returns expected action ID'
    );
    $actionId = null;

    $remaining = as_get_scheduled_actions([
        'hook' => $hook,
        'args' => $args,
        'group' => $group,
        'status' => 'pending',
        'per_page' => 2,
    ], 'ids');
    $assert($remaining === [], 'cancelled ID is no longer pending');
} finally {
    as_unschedule_action($hook, $args, $group);
}

$residual = as_get_scheduled_actions([
    'hook' => $hook,
    'group' => $group,
    'status' => 'pending',
    'per_page' => -1,
], 'ids');
$assert($residual === [], 'real test leaves zero hook/group actions');
$assert(
    $scheduleReturnType === 'int'
        && in_array($findReturnType, ['int', 'string'], true)
        && in_array($cancelReturnType, ['int', 'string'], true),
    'real provider return types observed safely'
);

$version = class_exists('ActionScheduler_Versions')
    ? ActionScheduler_Versions::instance()->latest_version()
    : 'unknown';
echo "durable retry external scheduler real: {$assertions} assertions"
    . " version={$version}"
    . " schedule_type={$scheduleReturnType}"
    . " find_type={$findReturnType}"
    . " cancel_type={$cancelReturnType}\n";
