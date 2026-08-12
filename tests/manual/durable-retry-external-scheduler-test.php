<?php

declare(strict_types=1);

$provider = [
    'schedule_return' => 41,
    'get_queue' => [],
    'cancel_return' => null,
    'schedule_throw' => false,
    'get_throw' => false,
    'cancel_throw' => false,
    'calls' => [],
];

function as_schedule_single_action(
    int $timestamp,
    string $hook,
    array $args = [],
    string $group = '',
    bool $unique = false
): mixed {
    global $provider;
    $provider['calls'][] = ['schedule', $timestamp, $hook, $args, $group, $unique];
    if ($provider['schedule_throw']) {
        throw new RuntimeException('provider schedule detail');
    }

    return $provider['schedule_return'];
}

function as_get_scheduled_actions(array $query = [], string $format = 'OBJECT'): mixed
{
    global $provider;
    $provider['calls'][] = ['find', $query, $format];
    if ($provider['get_throw']) {
        throw new RuntimeException('provider find detail');
    }

    return array_shift($provider['get_queue']) ?? [];
}

function as_unschedule_action(
    string $hook,
    array $args = [],
    string $group = ''
): mixed {
    global $provider;
    $provider['calls'][] = ['cancel', $hook, $args, $group];
    if ($provider['cancel_throw']) {
        throw new RuntimeException('provider cancel detail');
    }

    return $provider['cancel_return'];
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalSchedulerInterface;
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
$args = ['schedule_id' => 10, 'generation' => 1];
$scheduledFor = '2035-01-01 00:00:00';

$interface = new ReflectionClass(DurableRetryExternalSchedulerInterface::class);
$assert(
    array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $interface->getMethods()
    ) === ['schedule', 'findPending', 'cancel'],
    'minimal external scheduler API'
);
$resultConstants = array_map(
    static fn (ReflectionClassConstant $constant): mixed => $constant->getValue(),
    array_filter(
        (new ReflectionClass(DurableRetryExternalScheduleResult::class))
            ->getReflectionConstants(),
        static fn (ReflectionClassConstant $constant): bool => $constant->isPublic()
    )
);
$assert(array_values($resultConstants) === [
    'scheduled',
    'already_scheduled',
    'found',
    'not_found',
    'cancelled',
    'already_absent',
    'unavailable',
    'invalid_request',
    'external_error',
], 'closed result codes');
foreach ([
    DurableRetryExternalScheduleResult::SCHEDULED,
    DurableRetryExternalScheduleResult::ALREADY_SCHEDULED,
    DurableRetryExternalScheduleResult::FOUND,
    DurableRetryExternalScheduleResult::CANCELLED,
] as $code) {
    try {
        new DurableRetryExternalScheduleResult($code);
        $assert(false, "{$code} without ID rejected");
    } catch (InvalidArgumentException) {
        $assert(true, "{$code} without ID rejected");
    }
}
$validWithoutId = new DurableRetryExternalScheduleResult(
    DurableRetryExternalScheduleResult::NOT_FOUND
);
$validWithId = new DurableRetryExternalScheduleResult(
    DurableRetryExternalScheduleResult::FOUND,
    1
);
$assert(
    $validWithoutId->scheduledActionId() === null
        && $validWithId->scheduledActionId() === 1,
    'valid result combinations accepted'
);
foreach ([
    static fn () => new DurableRetryExternalScheduleResult('unknown'),
    static fn () => new DurableRetryExternalScheduleResult(
        DurableRetryExternalScheduleResult::FOUND,
        0
    ),
    static fn () => new DurableRetryExternalScheduleResult(
        DurableRetryExternalScheduleResult::FOUND,
        -1
    ),
] as $invalidResult) {
    try {
        $invalidResult();
        $assert(false, 'invalid result combination rejected');
    } catch (InvalidArgumentException) {
        $assert(true, 'invalid result combination rejected');
    }
}
foreach ([
    DurableRetryExternalScheduleResult::NOT_FOUND,
    DurableRetryExternalScheduleResult::ALREADY_ABSENT,
    DurableRetryExternalScheduleResult::UNAVAILABLE,
    DurableRetryExternalScheduleResult::INVALID_REQUEST,
    DurableRetryExternalScheduleResult::EXTERNAL_ERROR,
] as $code) {
    try {
        new DurableRetryExternalScheduleResult($code, 1);
        $assert(false, "{$code} with ID rejected");
    } catch (InvalidArgumentException) {
        $assert(true, "{$code} with ID rejected");
    }
}
$properties = (new ReflectionClass(DurableRetryExternalScheduleResult::class))
    ->getProperties();
$assert(
    count(array_filter(
        $properties,
        static fn (ReflectionProperty $property): bool => $property->isReadOnly()
    )) === count($properties),
    'result is immutable'
);

$assert(DurableRetryExternalScheduleCatalog::hooks() === [
    'veciahorra_durable_retry_reconciliation',
    'veciahorra_durable_retry_business_completion',
    'veciahorra_durable_retry_delivery_completion',
    'veciahorra_durable_retry_fulfillment_completion',
], 'four dedicated durable hooks');
$assert($group === 'veciahorra-durable-retry', 'dedicated group');

$originalTimezone = date_default_timezone_get();
date_default_timezone_set('Pacific/Auckland');
$utcTimestamp = DurableRetryExternalScheduleCatalog::timestamp('2035-01-01 00:00:00');
date_default_timezone_set('America/Santiago');
$assert(
    DurableRetryExternalScheduleCatalog::timestamp('2035-01-01 00:00:00')
        === $utcTimestamp,
    'UTC parsing ignores global PHP timezone'
);
date_default_timezone_set($originalTimezone);
$assert(
    DurableRetryExternalScheduleCatalog::timestamp('2035-01-01 23:59:58')
        === 2051308798,
    'day boundary preserves exact seconds'
);
$assert(
    DurableRetryExternalScheduleCatalog::timestamp('2036-02-29 00:00:00')
        === 2087856000,
    'valid leap day'
);
foreach ([
    '2035-2-01 00:00:00',
    '2035-01-1 00:00:00',
    '2035-02-29 00:00:00',
    ' 2035-01-01 00:00:00',
    '2035-01-01 00:00:00 ',
    '2035-01-01T00:00:00Z',
    '2035-01-01 00:00:00+00:00',
    '2051222400',
    '1969-12-31 23:59:59',
] as $invalidTimestamp) {
    try {
        DurableRetryExternalScheduleCatalog::timestamp($invalidTimestamp);
        $assert(false, 'invalid or pre-epoch timestamp rejected');
    } catch (InvalidArgumentException) {
        $assert(true, 'invalid or pre-epoch timestamp rejected');
    }
}

$provider['schedule_return'] = 41;
$scheduled = $adapter->schedule($hook, $args, $group, $scheduledFor);
$assert(
    $scheduled->code() === DurableRetryExternalScheduleResult::SCHEDULED
        && $scheduled->scheduledActionId() === 41,
    'successful unique schedule'
);
$scheduleCall = $provider['calls'][0];
$assert(
    $scheduleCall[1] === 2051222400
        && $scheduleCall[3] === $args
        && $scheduleCall[4] === $group
        && $scheduleCall[5] === true,
    'exact UTC instant, deterministic arguments and unique operation'
);

foreach ([0, -1, '41', false, null, true, 1.5, [], new stdClass()] as $invalidReturn) {
    $provider['schedule_return'] = $invalidReturn;
    $provider['get_queue'] = [[]];
    $result = $adapter->schedule($hook, $args, $group, $scheduledFor);
    $assert(
        $result->code() === DurableRetryExternalScheduleResult::EXTERNAL_ERROR,
        'invalid schedule return rejected'
    );
}
$provider['schedule_return'] = 0;
$provider['get_queue'] = [[77]];
$already = $adapter->schedule($hook, $args, $group, $scheduledFor);
$assert(
    $already->code() === DurableRetryExternalScheduleResult::ALREADY_SCHEDULED
        && $already->scheduledActionId() === 77,
    'unique collision resolves exact pending action'
);
$provider['schedule_throw'] = true;
$assert(
    $adapter->schedule($hook, $args, $group, $scheduledFor)->code()
        === DurableRetryExternalScheduleResult::EXTERNAL_ERROR,
    'schedule provider exception closed'
);
$provider['schedule_throw'] = false;

foreach ([
    ['unknown_hook', $args, $group, $scheduledFor],
    [$hook, $args, 'wrong-group', $scheduledFor],
    [$hook, ['schedule_id' => 10], $group, $scheduledFor],
    [$hook, ['schedule_id' => 10, 'generation' => 1, 'extra' => 2], $group, $scheduledFor],
    [$hook, ['schedule_id' => '10', 'generation' => 1], $group, $scheduledFor],
    [$hook, ['schedule_id' => 10, 'generation' => 0], $group, $scheduledFor],
    [$hook, $args, $group, '2035-01-01T00:00:00Z'],
] as [$invalidHook, $invalidArgs, $invalidGroup, $invalidTime]) {
    $assert(
        $adapter->schedule(
            $invalidHook,
            $invalidArgs,
            $invalidGroup,
            $invalidTime
        )->code() === DurableRetryExternalScheduleResult::INVALID_REQUEST,
        'invalid external request rejected'
    );
}
$reversed = ['generation' => 1, 'schedule_id' => 10];
$reversedBefore = $reversed;
$provider['schedule_return'] = 42;
$adapter->schedule($hook, $reversed, $group, $scheduledFor);
$lastSchedule = end($provider['calls']);
$assert(
    $lastSchedule[3] === $args && $reversed === $reversedBefore,
    'argument order normalized without mutating caller input'
);

$provider['get_queue'] = [[88]];
$found = $adapter->findPending($hook, $args, $group);
$assert(
    $found->code() === DurableRetryExternalScheduleResult::FOUND
        && $found->scheduledActionId() === 88,
    'pending action found'
);
$findCall = end($provider['calls']);
$assert(
    $findCall[1]['hook'] === $hook
        && $findCall[1]['args'] === $args
        && $findCall[1]['group'] === $group
        && $findCall[1]['status'] === 'pending'
        && $findCall[1]['per_page'] === 2
        && $findCall[2] === 'ids',
    'search is exact and pending-only'
);
$provider['get_queue'] = [[]];
$assert(
    $adapter->findPending($hook, $args, $group)->code()
        === DurableRetryExternalScheduleResult::NOT_FOUND,
    'no pending action'
);
$provider['get_queue'] = [['88']];
$assert(
    $adapter->findPending($hook, $args, $group)->scheduledActionId() === 88,
    'canonical ID string from public provider normalized'
);
foreach ([
    [0],
    [-1],
    ['0'],
    ['00'],
    ['01'],
    ['+1'],
    ['-1'],
    ['1.0'],
    ['1e3'],
    [' 1'],
    ['1 '],
    [''],
    [(string) PHP_INT_MAX . '0'],
    [1.5],
    [true],
    [88, 89],
    'unexpected',
] as $unexpected) {
    $provider['get_queue'] = [$unexpected];
    $assert(
        $adapter->findPending($hook, $args, $group)->code()
            === DurableRetryExternalScheduleResult::EXTERNAL_ERROR,
        'unexpected find response rejected'
    );
}
$provider['get_throw'] = true;
$assert(
    $adapter->findPending($hook, $args, $group)->code()
        === DurableRetryExternalScheduleResult::EXTERNAL_ERROR,
    'find provider exception closed'
);
$provider['get_throw'] = false;

$provider['get_queue'] = [[91], []];
$provider['cancel_return'] = 91;
$cancelled = $adapter->cancel(91, $hook, $args, $group);
$assert(
    $cancelled->code() === DurableRetryExternalScheduleResult::CANCELLED
        && $cancelled->scheduledActionId() === 91,
    'cancellation confirmed by exact ID and absence'
);
$provider['get_queue'] = [[]];
$assert(
    $adapter->cancel(91, $hook, $args, $group)->code()
        === DurableRetryExternalScheduleResult::ALREADY_ABSENT,
    'already absent cancellation'
);
$provider['get_queue'] = [[92]];
$assert(
    $adapter->cancel(91, $hook, $args, $group)->code()
        === DurableRetryExternalScheduleResult::EXTERNAL_ERROR,
    'foreign action cannot be cancelled'
);
$provider['get_queue'] = [[91], [91]];
$provider['cancel_return'] = 91;
$assert(
    $adapter->cancel(91, $hook, $args, $group)->code()
        === DurableRetryExternalScheduleResult::EXTERNAL_ERROR,
    'ambiguous cancellation is not success'
);
$provider['get_queue'] = [[91], []];
$provider['cancel_return'] = 92;
$assert(
    $adapter->cancel(91, $hook, $args, $group)->code()
        === DurableRetryExternalScheduleResult::EXTERNAL_ERROR,
    'different cancellation ID is never success'
);
foreach (['91', false, [], new stdClass()] as $unexpectedCancellationReturn) {
    $provider['get_queue'] = [[91], []];
    $provider['cancel_return'] = $unexpectedCancellationReturn;
    $assert(
        $adapter->cancel(91, $hook, $args, $group)->code()
            === DurableRetryExternalScheduleResult::EXTERNAL_ERROR,
        'unexpected cancellation return is not success'
    );
}
$provider['get_queue'] = [[91], []];
$provider['cancel_return'] = null;
$assert(
    $adapter->cancel(91, $hook, $args, $group)->code()
        === DurableRetryExternalScheduleResult::ALREADY_ABSENT,
    'disappearance before cancellation is already absent'
);
$provider['get_queue'] = [[91], [92]];
$provider['cancel_return'] = 91;
$assert(
    $adapter->cancel(91, $hook, $args, $group)->code()
        === DurableRetryExternalScheduleResult::EXTERNAL_ERROR,
    'new equivalent action is not confused with cancelled action'
);
$provider['get_queue'] = [[91, 92]];
$assert(
    $adapter->cancel(91, $hook, $args, $group)->code()
        === DurableRetryExternalScheduleResult::EXTERNAL_ERROR,
    'multiple pending matches block cancellation'
);
foreach (['completed', 'failed', 'cancelled', 'running'] as $nonPendingStatus) {
    $provider['get_queue'] = [[]];
    $assert(
        $adapter->cancel(91, $hook, $args, $group)->code()
            === DurableRetryExternalScheduleResult::ALREADY_ABSENT,
        "{$nonPendingStatus} action is absent from pending query"
    );
}
$provider['get_queue'] = [[91]];
$provider['cancel_throw'] = true;
$assert(
    $adapter->cancel(91, $hook, $args, $group)->code()
        === DurableRetryExternalScheduleResult::EXTERNAL_ERROR,
    'cancel provider exception closed'
);
$provider['cancel_throw'] = false;
$assert(
    $adapter->cancel(0, $hook, $args, $group)->code()
        === DurableRetryExternalScheduleResult::INVALID_REQUEST,
    'invalid cancellation ID'
);

echo "durable retry external scheduler: {$assertions} assertions\n";
