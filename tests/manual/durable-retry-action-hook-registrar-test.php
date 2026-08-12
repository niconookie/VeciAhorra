<?php

declare(strict_types=1);

$registeredActions = [];
$scheduledActions = 0;

function add_action(
    string $hook,
    callable $callback,
    int $priority = 10,
    int $acceptedArgs = 1
): bool {
    global $registeredActions;
    $registeredActions[] = [$hook, $callback, $priority, $acceptedArgs];
    return true;
}

function as_schedule_single_action(mixed ...$arguments): int
{
    global $scheduledActions;
    ++$scheduledActions;
    return 1;
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Contracts\DurableRetryExecutorInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleCatalog;
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryActionCallback;
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryActionHookRegistrar;

final class HookRegistrarExecutorDouble implements DurableRetryExecutorInterface
{
    public array $calls = [];
    public mixed $next;

    public function execute(
        string $hook,
        int $scheduleId,
        int $generation
    ): DurableRetryExecutionResult {
        $this->calls[] = func_get_args();
        if ($this->next instanceof Throwable) {
            throw $this->next;
        }
        return $this->next;
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) { throw new RuntimeException($message); }
};
$executor = new HookRegistrarExecutorDouble();
$executor->next = new DurableRetryExecutionResult(
    DurableRetryExecutionResult::NOT_FOUND,
    70,
    3
);
$callback = new DurableRetryActionCallback($executor);
$registrar = new DurableRetryActionHookRegistrar($callback);
$assert($registeredActions === [], 'construction registers nothing');
$assert($scheduledActions === 0, 'construction schedules nothing');

$registrar->register();
$hooks = DurableRetryExternalScheduleCatalog::hooks();
$assert(count($registeredActions) === 4, 'exactly four actions registered');
$assert(array_column($registeredActions, 0) === $hooks, 'only canonical hooks registered');
foreach ($registeredActions as [$hook, $callable, $priority, $acceptedArgs]) {
    $assert(is_callable($callable), "callable {$hook}");
    $assert($priority === 10, "priority {$hook}");
    $assert($acceptedArgs === 2, "accepted args {$hook}");
}
$assert($scheduledActions === 0, 'registration schedules nothing');

$registrar->register();
$assert(count($registeredActions) === 4, 'second registration is idempotent');

foreach ($registeredActions as $index => [$hook, $callable]) {
    $before = count($executor->calls);
    $return = $callable(70 + $index, 3 + $index);
    $assert($return === null, "hook return ignored {$hook}");
    $assert(count($executor->calls) === $before + 1, "one delegation {$hook}");
    $assert(
        $executor->calls[$before] === [$hook, 70 + $index, 3 + $index],
        "bound hook identity {$hook}"
    );
}
$assert(count($executor->calls) === 4, 'each adapter delegates once');
$assert($scheduledActions === 0, 'valid invocation schedules nothing directly');

$invalidCallable = $registeredActions[0][1];
$before = count($executor->calls);
$caught = null;
try {
    $invalidCallable('70', 3);
} catch (InvalidArgumentException $exception) {
    $caught = $exception;
}
$assert($caught instanceof InvalidArgumentException, 'invalid payload propagates');
$assert(
    $caught?->getMessage() === 'Invalid durable retry callback invocation.',
    'invalid payload message remains safe'
);
$assert(count($executor->calls) === $before, 'invalid payload never reaches executor');
$assert($scheduledActions === 0, 'invalid payload schedules nothing');

$failure = new RuntimeException('executor failure');
$executor->next = $failure;
$caught = null;
try {
    $registeredActions[1][1](80, 4);
} catch (RuntimeException $exception) {
    $caught = $exception;
}
$assert($caught === $failure, 'executor exception propagates unchanged');
$assert(count($executor->calls) === $before + 1, 'throwing executor invoked once');
$assert($scheduledActions === 0, 'exception schedules nothing');

echo "durable retry action hook registrar: {$assertions} assertions\n";
