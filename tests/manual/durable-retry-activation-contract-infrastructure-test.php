<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialTransferAuthorityInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacyExclusionInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentityCollection;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityBatchResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityResult;

final class A1LegacyExclusionDouble implements DurableRetryLegacyExclusionInterface
{
    public function classify(
        DurableRetryAuthorityIdentity $identity
    ): DurableRetryLegacyAuthorityResult {
        return DurableRetryLegacyAuthorityResult::legacy();
    }

    public function classifyBatch(
        DurableRetryAuthorityIdentityCollection $identities
    ): DurableRetryLegacyAuthorityBatchResult {
        return DurableRetryLegacyAuthorityBatchResult::fromEntries($identities);
    }
}

final class A1InitialTransferDouble implements DurableRetryInitialTransferAuthorityInterface
{
    public function transferReconciliation(
        DurableRetryInitialTransferRequest $request
    ): DurableRetryInitialTransferResult {
        return DurableRetryInitialTransferResult::transferred(
            $request->generationIdentity()
        );
    }
}

$root = dirname(__DIR__, 2);
$relativeFiles = [
    'app/Modules/Orders/Exceptions/DurableRetryActivationContractException.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryAuthorityIdentity.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryGenerationIdentity.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryAuthorityIdentityCollection.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryIndeterminateReason.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryLegacyAuthorityResult.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryLegacyAuthorityEntry.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryLegacyAuthorityBatchResult.php',
    'app/Modules/Orders/Contracts/DurableRetryLegacyExclusionInterface.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialTransferRequest.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialTransferReason.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialTransferResult.php',
    'app/Modules/Orders/Contracts/DurableRetryInitialTransferAuthorityInterface.php',
];
$harnesses = [
    'tests/manual/durable-retry-activation-authority-contract-test.php',
    'tests/manual/durable-retry-activation-transfer-contract-test.php',
    'tests/manual/durable-retry-activation-contract-infrastructure-test.php',
];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

foreach (array_merge($relativeFiles, $harnesses) as $relative) {
    $assert(is_file($root . '/' . $relative), 'authorized file exists: ' . $relative);
}

$classes = [
    'VeciAhorra\\Modules\\Orders\\Exceptions\\DurableRetryActivationContractException',
    'VeciAhorra\\Modules\\Orders\\Domain\\DurableRetry\\DurableRetryAuthorityIdentity',
    'VeciAhorra\\Modules\\Orders\\Domain\\DurableRetry\\DurableRetryGenerationIdentity',
    'VeciAhorra\\Modules\\Orders\\Domain\\DurableRetry\\DurableRetryAuthorityIdentityCollection',
    'VeciAhorra\\Modules\\Orders\\Domain\\DurableRetry\\DurableRetryIndeterminateReason',
    'VeciAhorra\\Modules\\Orders\\Domain\\DurableRetry\\DurableRetryLegacyAuthorityResult',
    'VeciAhorra\\Modules\\Orders\\Domain\\DurableRetry\\DurableRetryLegacyAuthorityEntry',
    'VeciAhorra\\Modules\\Orders\\Domain\\DurableRetry\\DurableRetryLegacyAuthorityBatchResult',
    'VeciAhorra\\Modules\\Orders\\Domain\\DurableRetry\\DurableRetryInitialTransferRequest',
    'VeciAhorra\\Modules\\Orders\\Domain\\DurableRetry\\DurableRetryInitialTransferReason',
    'VeciAhorra\\Modules\\Orders\\Domain\\DurableRetry\\DurableRetryInitialTransferResult',
];
foreach ($classes as $class) {
    $reflection = new ReflectionClass($class);
    $assert($reflection->isFinal(), $class . ' final');
}

$classify = new ReflectionMethod(DurableRetryLegacyExclusionInterface::class, 'classify');
$assert(
    (string) $classify->getParameters()[0]->getType()
        === DurableRetryAuthorityIdentity::class,
    'individual identity typed'
);
$assert(
    (string) $classify->getReturnType()
        === DurableRetryLegacyAuthorityResult::class,
    'individual result typed'
);
$classifyBatch = new ReflectionMethod(
    DurableRetryLegacyExclusionInterface::class,
    'classifyBatch'
);
$assert(
    (string) $classifyBatch->getParameters()[0]->getType()
        === DurableRetryAuthorityIdentityCollection::class,
    'batch input typed'
);
$assert(
    (string) $classifyBatch->getReturnType()
        === DurableRetryLegacyAuthorityBatchResult::class,
    'batch result typed'
);
$transfer = new ReflectionMethod(
    DurableRetryInitialTransferAuthorityInterface::class,
    'transferReconciliation'
);
$assert(
    (string) $transfer->getParameters()[0]->getType()
        === DurableRetryInitialTransferRequest::class,
    'transfer request typed'
);
$assert(
    (string) $transfer->getReturnType()
        === DurableRetryInitialTransferResult::class,
    'transfer result typed'
);

$identity = DurableRetryAuthorityIdentity::reconciliation(1);
$legacyDouble = new A1LegacyExclusionDouble();
$assert($legacyDouble->classify($identity)->isLegacyAuthorized(), 'double usable');
$assert(
    $legacyDouble->classifyBatch(
        DurableRetryAuthorityIdentityCollection::fromIdentities()
    )->isEmpty(),
    'batch double usable'
);
$transferDouble = new A1InitialTransferDouble();
$assert(
    $transferDouble->transferReconciliation(
        DurableRetryInitialTransferRequest::reconciliation(
            $identity,
            1,
            new DateTimeImmutable('2035-01-01 00:00:00 UTC')
        )
    )->permitsInitialExternalScheduling(),
    'transfer double usable'
);

$forbidden = [
    '$wpdb',
    'as_schedule_',
    'as_has_',
    'as_unschedule_',
    'add_action(',
    'do_action(',
    'wp_schedule_',
    'DurableRetryExecutor',
    'DurableRetryActionCallback',
    'DurableRetryActionHookRegistrar',
    'DurableRetryProcessor',
    'ActionSchedulerDurableRetryAdapter',
    'DurableCompletionScheduler',
    'DurableCompletionWorkers',
    'DurableCompletionRecovery',
    'SELECT ',
    'INSERT ',
    'UPDATE ',
    'DELETE FROM',
];
foreach ($relativeFiles as $relative) {
    $source = file_get_contents($root . '/' . $relative);
    $assert(is_string($source), 'source readable: ' . $relative);
    $assert(str_contains($source, 'declare(strict_types=1);'), 'strict: ' . $relative);
    foreach ($forbidden as $needle) {
        $assert(! str_contains($source, $needle), 'pure ' . $needle . ': ' . $relative);
    }
}

$forbiddenMethods = [
    'rollback',
    'delete',
    'forceTransfer',
    'transferBack',
    'overwrite',
    'release',
];
foreach ([
    DurableRetryLegacyExclusionInterface::class,
    DurableRetryInitialTransferAuthorityInterface::class,
] as $interface) {
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass($interface))->getMethods()
    );
    foreach ($forbiddenMethods as $method) {
        $assert(! in_array($method, $methods, true), 'no method ' . $method);
    }
}

echo "OK durable retry activation contract infrastructure ({$assertions} assertions)\n";
