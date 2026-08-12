<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialScheduleResolverInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryScheduleRepositoryInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialScheduleResolutionResult;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialScheduleResolver;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$allowlist = [
    'app/Modules/Orders/Contracts/DurableRetryInitialScheduleResolverInterface.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialScheduleResolutionResult.php',
    'app/Modules/Orders/Services/DurableRetryInitialScheduleResolver.php',
    'tests/manual/durable-retry-initial-schedule-resolver-test.php',
    'tests/manual/durable-retry-initial-schedule-resolver-infrastructure-test.php',
];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$interfacePath = $root . '/' . $allowlist[0];
$resultPath = $root . '/' . $allowlist[1];
$servicePath = $root . '/' . $allowlist[2];
$interfaceSource = file_get_contents($interfacePath);
$resultSource = file_get_contents($resultPath);
$serviceSource = file_get_contents($servicePath);

foreach ($allowlist as $path) {
    $assert(is_file($root . '/' . $path), "missing {$path}");
}
$assert(count($allowlist) === 5, 'five-file allowlist');
$assert(interface_exists(DurableRetryInitialScheduleResolverInterface::class), 'interface FQCN');
$assert(class_exists(DurableRetryInitialScheduleResolutionResult::class), 'result FQCN');
$assert(class_exists(DurableRetryInitialScheduleResolver::class), 'service FQCN');
$assert((new ReflectionClass(DurableRetryInitialScheduleResolver::class))->isFinal(), 'service final');
$assert(is_subclass_of(DurableRetryInitialScheduleResolver::class, DurableRetryInitialScheduleResolverInterface::class), 'implements interface');
$assert(count((new ReflectionClass(DurableRetryInitialScheduleResolverInterface::class))->getMethods()) === 1, 'interface one method');
$assert((new ReflectionClass(DurableRetryInitialScheduleResolverInterface::class))->getMethod('resolve')->getNumberOfParameters() === 2, 'resolve two parameters');
$assert((string) (new ReflectionClass(DurableRetryInitialScheduleResolverInterface::class))->getMethod('resolve')->getReturnType() === DurableRetryInitialScheduleResolutionResult::class, 'resolve return');
$constructor = (new ReflectionClass(DurableRetryInitialScheduleResolver::class))->getConstructor();
$assert($constructor !== null, 'constructor exists');
$assert($constructor?->getNumberOfParameters() === 1, 'one dependency');
$assert((string) $constructor?->getParameters()[0]->getType() === DurableRetryScheduleRepositoryInterface::class, 'repository dependency');
$public = array_filter(
    (new ReflectionClass(DurableRetryInitialScheduleResolver::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === DurableRetryInitialScheduleResolver::class
);
$assert(count($public) === 2, 'constructor plus resolve public');
$assert(substr_count($serviceSource, 'findByIdentity(') === 1, 'one structural repository call');
$assert(str_contains($serviceSource, 'DurableRetryStage::RECONCILIATION'), 'fixed reconciliation');
$assert(str_contains($serviceSource, 'INITIAL_GENERATION'), 'fixed generation');

$states = [
    'resolved_dispatching',
    'resolved_scheduled',
    'not_found',
    'incompatible',
    'read_error',
];
foreach ($states as $state) {
    $assert(substr_count($resultSource, "'{$state}'") === 1, "single state {$state}");
}
$assert(count($states) === 5, 'closed five-state catalog');
$assert(str_contains($resultSource, 'RESOLVED_DISPATCHING'), 'dispatching continuable');
$assert(str_contains($resultSource, 'RESOLVED_SCHEDULED'), 'scheduled continuable');
$assert(str_contains($resultSource, 'return false;'), 'legacy always false');

$forbidden = [
    ' INSERT ', ' UPDATE ', ' DELETE ', 'FOR UPDATE', 'START TRANSACTION',
    'COMMIT', 'ROLLBACK', 'add_action', 'do_action', 'as_schedule_',
    'DurableCompletion', 'InitialTransferAuthorityInterface',
    'InitialAuthorityProducerInterface', 'ExternalScheduleCoordinator',
    'DurableRetryExecutor', 'ProcessorRegistry', 'sleep(',
    'while (', 'for (', 'foreach (', 'error_log(', 'retry(',
];
foreach ($forbidden as $token) {
    $assert(! str_contains($serviceSource, $token), "forbidden {$token}");
}

if ($assertions !== 52) {
    throw new RuntimeException("Unexpected infrastructure assertions: {$assertions}.");
}

echo "PASS durable-retry-initial-schedule-resolver-infrastructure-test (52 assertions)\n";
