<?php

declare(strict_types=1);

if (! function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $default;
    }
}

if (! class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public int $queries = 0;

        public function query(string $query): bool
        {
            ++$this->queries;
            return false;
        }

        public function prepare(string $query, mixed ...$arguments): string
        {
            return $query;
        }
    }
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Core\Application;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryStageProcessorResolverInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Orders\Services\DurableRetryBusinessCompletionProcessor;
use VeciAhorra\Modules\Orders\Services\DurableRetryDeliveryCompletionProcessor;
use VeciAhorra\Modules\Orders\Services\DurableRetryExecutor;
use VeciAhorra\Modules\Orders\Services\DurableRetryFulfillmentProcessor;
use VeciAhorra\Modules\Orders\Services\DurableRetryReconciliationProcessor;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$property = static function (object $object, string $name): mixed {
    $reflection = new ReflectionProperty($object, $name);
    return $reflection->getValue($object);
};

$database = new wpdb();
$GLOBALS['wpdb'] = $database;
$application = new Application();
$assert($database->queries === 0, 'application construction performs zero SQL');

$executor = $application->durableRetryExecutor();
$assert($executor instanceof DurableRetryExecutor, 'composition exposes executor');
$assert($database->queries === 0, 'graph construction performs zero SQL');
$assert($application->durableRetryExecutor() === $executor, 'executor is shared in one graph');

$resolver = $application->container()->make(
    DurableRetryStageProcessorResolverInterface::class
);
$assert($resolver instanceof DurableRetryStageProcessorResolverInterface, 'resolver contract');
$assert($property($executor, 'processorResolver') === $resolver, 'executor receives same registry');

$expected = [
    DurableRetryStage::RECONCILIATION => DurableRetryReconciliationProcessor::class,
    DurableRetryStage::BUSINESS_COMPLETION => DurableRetryBusinessCompletionProcessor::class,
    DurableRetryStage::DELIVERY_COMPLETION => DurableRetryDeliveryCompletionProcessor::class,
    DurableRetryStage::FULFILLMENT_COMPLETION => DurableRetryFulfillmentProcessor::class,
];
$processors = [];
foreach ($expected as $stage => $class) {
    $first = $resolver->resolve($stage);
    $second = $resolver->resolve($stage);
    $assert($first instanceof $class, "processor {$stage}");
    $assert($first === $second, "processor identity {$stage}");
    $assert($first->stage() === $stage, "processor stage {$stage}");
    $processors[$stage] = $first;
}
$assert(
    count(array_unique(array_map('spl_object_id', $processors))) === 4,
    'exactly four distinct processors'
);

$reconciliation = $processors[DurableRetryStage::RECONCILIATION];
$reconciliationAttempts = $property($reconciliation, 'attempts');
$assert(
    $property($reconciliation, 'claims')
        === $property($reconciliationAttempts, 'claims'),
    'reconciliation claim authority shared'
);
$assert(
    $property($reconciliation, 'reconciliations')
        === $property($reconciliationAttempts, 'reconciliations'),
    'reconciliation read authority shared'
);

foreach ([
    DurableRetryStage::BUSINESS_COMPLETION,
    DurableRetryStage::DELIVERY_COMPLETION,
    DurableRetryStage::FULFILLMENT_COMPLETION,
] as $stage) {
    $processor = $processors[$stage];
    $assert(
        $property($processor, 'readAuthority')
            === $property($property($processor, 'attemptProcessor'), 'completions'),
        "completion repository shared {$stage}"
    );
}
$businessAttempts = $property(
    $processors[DurableRetryStage::BUSINESS_COMPLETION],
    'attemptProcessor'
);
$deliveryAttempts = $property(
    $processors[DurableRetryStage::DELIVERY_COMPLETION],
    'attemptProcessor'
);
$assert(
    $property($businessAttempts, 'reconciliations')
        === $property($reconciliation, 'reconciliations'),
    'reconciliation authority shared across stages'
);
$assert(
    $property($businessAttempts, 'orders')
        === $property($deliveryAttempts, 'orders'),
    'order repository shared across completion stages'
);

$coordinator = $property($executor, 'coordinator');
$assert(
    $property($executor, 'repository') === $property($coordinator, 'repository'),
    'durable repository shared'
);
$assert(
    $property($executor, 'utcNow') === $property($coordinator, 'utcNow'),
    'UTC clock shared'
);
$assert($database->queries === 0, 'identity inspection performs zero SQL');

$otherDatabase = new wpdb();
$GLOBALS['wpdb'] = $otherDatabase;
$otherApplication = new Application();
$otherExecutor = $otherApplication->durableRetryExecutor();
$otherResolver = $otherApplication->container()->make(
    DurableRetryStageProcessorResolverInterface::class
);
$assert($otherExecutor !== $executor, 'independent applications have independent executors');
$assert($otherResolver !== $resolver, 'independent applications have independent registries');
$assert(
    $otherResolver->resolve(DurableRetryStage::RECONCILIATION)
        !== $processors[DurableRetryStage::RECONCILIATION],
    'independent graphs have independent processors'
);
$assert($otherDatabase->queries === 0, 'second graph performs zero SQL');

echo "durable retry composition: {$assertions} assertions\n";
