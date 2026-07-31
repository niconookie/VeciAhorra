<?php

declare(strict_types=1);

if (! class_exists('wpdb')) {
    class wpdb
    {
        public int $prefixReads = 0;
        public int $queries = 0;
        public bool $failNextPrefixRead = false;
        public ?Closure $onPrefixRead = null;

        public function __get(string $name): mixed
        {
            if ($name !== 'prefix') {
                return null;
            }
            ++$this->prefixReads;
            if ($this->onPrefixRead !== null) {
                ($this->onPrefixRead)();
            }
            if ($this->failNextPrefixRead) {
                $this->failNextPrefixRead = false;
                throw new RuntimeException('original composition failure');
            }

            return 'wp_';
        }
    }
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationConfigurationValueReaderInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalSchedulerInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacySchedulerInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationConfigurationValue;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleResult;
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryProductionComposition;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialProductionRouter;

final class A9ConfigurationReaderDouble implements DurableRetryActivationConfigurationValueReaderInterface
{
    public int $calls = 0;
    public function read(): DurableRetryActivationConfigurationValue
    {
        ++$this->calls;
        return DurableRetryActivationConfigurationValue::absent();
    }
}

final class A9ExternalSchedulerDouble implements DurableRetryExternalSchedulerInterface
{
    public int $calls = 0;
    public function schedule(string $hook, array $arguments, string $group, string $scheduledFor): DurableRetryExternalScheduleResult
    {
        ++$this->calls;
        throw new RuntimeException('must not schedule while composing');
    }
    public function findPending(string $hook, array $arguments, string $group): DurableRetryExternalScheduleResult
    {
        ++$this->calls;
        throw new RuntimeException('must not inspect scheduler while composing');
    }
    public function cancel(int $scheduledActionId, string $hook, array $arguments, string $group): DurableRetryExternalScheduleResult
    {
        ++$this->calls;
        throw new RuntimeException('must not cancel while composing');
    }
}

final class A9LegacySchedulerDouble implements DurableRetryLegacySchedulerInterface
{
    public int $calls = 0;
    public function scheduleReconciliation(int $reconciliationId): bool
    {
        ++$this->calls;
        throw new RuntimeException('must not schedule legacy while composing');
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$property = static function (object $object, string $name): mixed {
    return (new ReflectionProperty($object, $name))->getValue($object);
};

$database = new wpdb();
$reader = new A9ConfigurationReaderDouble();
$external = new A9ExternalSchedulerDouble();
$legacy = new A9LegacySchedulerDouble();
$clockCalls = 0;
$clock = static function () use (&$clockCalls): string {
    ++$clockCalls;
    return '2035-01-01 00:00:00';
};
$composition = new DurableRetryProductionComposition(
    $database,
    $reader,
    $external,
    $legacy,
    $clock
);

$router = $composition->router();
$assert($router instanceof DurableRetryInitialProductionRouter, 'first composition returns exact A8');
$assert($composition->router() === $router, 'second call returns same router');
for ($index = 0; $index < 10; ++$index) {
    $assert($composition->router() === $router, "stable router call {$index}");
}
$assert($property($composition, 'state') === 2, 'composition completes once');
$assert($property($composition, 'composedRouter') === $router, 'published router is exact instance');

$a5 = $property($router, 'authorityProducer');
$a6 = $property($router, 'scheduleResolver');
$a7 = $property($router, 'scheduleCoordinator');
$assert($property($router, 'legacyScheduler') === $legacy, 'legacy identity stable');
$assert($property($composition, 'configurationValueReader') === $reader, 'reader identity stable');
$assert($property($composition, 'externalScheduler') === $external, 'external scheduler identity stable');
$assert($property($composition, 'legacyScheduler') === $legacy, 'composition legacy identity stable');
$assert($property($composition, 'utcNow') === $clock, 'clock identity stable');

$policy = $property($a5, 'activation');
$source = $property($policy, 'source');
$transfer = $property($a5, 'transfer');
$a4Repository = $property($transfer, 'repository');
$a3Repository = $property($a5, 'authority');
$a6Repository = $property($a6, 'repository');
$externalCoordinator = $property($a7, 'coordinator');
$assert($property($source, 'reader') === $reader, 'single configuration authority');
$assert($property($a3Repository, 'database') === $database, 'A3 database identity');
$assert($property($a4Repository, 'database') === $database, 'A4 database identity');
$assert($property($a6Repository, 'database') === $database, 'A6 database identity');
$assert($property($externalCoordinator, 'repository') === $a6Repository, 'durable repository shared');
$assert($property($externalCoordinator, 'scheduler') === $external, 'external scheduler shared');
$assert($property($externalCoordinator, 'utcNow') === $clock, 'clock shared');
$assert($reader->calls === 0, 'configuration not read');
$assert($external->calls === 0, 'external scheduler not called');
$assert($legacy->calls === 0, 'legacy scheduler not called');
$assert($clockCalls === 0, 'clock not called');
$assert($database->queries === 0, 'zero SQL');

$readsAfterSuccess = $database->prefixReads;
$composition->router();
$assert($database->prefixReads === $readsAfterSuccess, 'reuse reconstructs nothing');

$failingDatabase = new wpdb();
$failingDatabase->failNextPrefixRead = true;
$retryComposition = new DurableRetryProductionComposition(
    $failingDatabase,
    new A9ConfigurationReaderDouble(),
    new A9ExternalSchedulerDouble(),
    new A9LegacySchedulerDouble(),
    static fn (): string => '2035-01-01 00:00:00'
);
$original = null;
try {
    $retryComposition->router();
} catch (Throwable $error) {
    $original = $error;
}
$assert($original instanceof RuntimeException, 'construction exception propagated');
$assert($original?->getMessage() === 'original composition failure', 'original error not wrapped');
$assert($property($retryComposition, 'state') === 0, 'failure resets state');
$assert($property($retryComposition, 'composedRouter') === null, 'failure publishes no router');
$retriedRouter = $retryComposition->router();
$assert($retriedRouter instanceof DurableRetryInitialProductionRouter, 'retry succeeds');
$assert($retryComposition->router() === $retriedRouter, 'retry result becomes stable');

$reentrantDatabase = new wpdb();
$reentrantComposition = new DurableRetryProductionComposition(
    $reentrantDatabase,
    new A9ConfigurationReaderDouble(),
    new A9ExternalSchedulerDouble(),
    new A9LegacySchedulerDouble(),
    static fn (): string => '2035-01-01 00:00:00'
);
$reentrantDatabase->onPrefixRead = static function () use ($reentrantComposition): void {
    $reentrantComposition->router();
};
$reentry = null;
try {
    $reentrantComposition->router();
} catch (Throwable $error) {
    $reentry = $error;
}
$assert($reentry instanceof LogicException, 're-entry uses LogicException');
$assert(
    $reentry?->getMessage() === 'Durable Retry production composition re-entry is not allowed.',
    'stable re-entry message'
);
$assert($property($reentrantComposition, 'state') === 0, 're-entry resets state');
$assert($property($reentrantComposition, 'composedRouter') === null, 're-entry publishes no router');
$reentrantDatabase->onPrefixRead = null;
$assert($reentrantComposition->router() instanceof DurableRetryInitialProductionRouter, 're-entry may retry');

$public = array_filter(
    (new ReflectionClass(DurableRetryProductionComposition::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    static fn (ReflectionMethod $method): bool =>
        $method->getDeclaringClass()->getName() === DurableRetryProductionComposition::class
);
$assert(
    array_map(static fn (ReflectionMethod $method): string => $method->getName(), $public)
        === ['__construct', 'router'],
    'no public internal-service access'
);

echo "durable retry production composition: 20 cases, {$assertions} assertions\n";
