<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Contracts\DurableRetryStageProcessorInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionContext;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Orders\Exceptions\DurableRetryProcessorConfigurationException;
use VeciAhorra\Modules\Orders\Services\DurableRetryProcessorRegistry;

final class RegistryProcessorDouble implements
    DurableRetryStageProcessorInterface
{
    public int $processCalls = 0;

    public function __construct(private readonly string $declaredStage)
    {
    }

    public function stage(): string
    {
        return $this->declaredStage;
    }

    public function process(
        DurableRetryExecutionContext $context
    ): DurableRetryProcessingResult {
        ++$this->processCalls;

        return DurableRetryProcessingResult::outcomeUncertain();
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (
    &$assertions
): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$expectReason = static function (
    string $reason,
    callable $operation,
    string $message
) use ($assert): void {
    try {
        $operation();
    } catch (DurableRetryProcessorConfigurationException $exception) {
        $assert($exception->reason() === $reason, $message . ' reason');

        return;
    }

    $assert(false, $message . ' exception');
};
$processors = static function (): array {
    return [
        DurableRetryStage::RECONCILIATION =>
            new RegistryProcessorDouble(DurableRetryStage::RECONCILIATION),
        DurableRetryStage::BUSINESS_COMPLETION =>
            new RegistryProcessorDouble(DurableRetryStage::BUSINESS_COMPLETION),
        DurableRetryStage::DELIVERY_COMPLETION =>
            new RegistryProcessorDouble(DurableRetryStage::DELIVERY_COMPLETION),
        DurableRetryStage::FULFILLMENT_COMPLETION =>
            new RegistryProcessorDouble(
                DurableRetryStage::FULFILLMENT_COMPLETION
            ),
    ];
};

$configured = $processors();
$registry = new DurableRetryProcessorRegistry($configured);
$assert(count(DurableRetryStage::all()) === 4, 'authoritative catalog');
foreach (DurableRetryStage::all() as $stage) {
    $assert($registry->resolve($stage) === $configured[$stage], $stage);
    $assert($registry->resolve($stage) === $registry->resolve($stage), $stage);
    $assert($configured[$stage]->processCalls === 0, $stage . ' is not run');
}

$reversed = array_reverse($configured, true);
$reversedRegistry = new DurableRetryProcessorRegistry($reversed);
foreach (DurableRetryStage::all() as $stage) {
    $assert($reversedRegistry->resolve($stage) === $configured[$stage], $stage);
}

$listed = array_values($configured);
$listRegistry = new DurableRetryProcessorRegistry($listed);
foreach (DurableRetryStage::all() as $stage) {
    $assert($listRegistry->resolve($stage) === $configured[$stage], $stage);
}
$listed[0] = new RegistryProcessorDouble(DurableRetryStage::RECONCILIATION);
$assert(
    $listRegistry->resolve(DurableRetryStage::RECONCILIATION)
        === $configured[DurableRetryStage::RECONCILIATION],
    'input mutation does not replace configured instance'
);

$expectReason(
    DurableRetryProcessorConfigurationException::
        INVALID_REGISTRY_CONFIGURATION,
    static fn () => new DurableRetryProcessorRegistry([]),
    'empty registry'
);
$missing = $processors();
unset($missing[DurableRetryStage::FULFILLMENT_COMPLETION]);
$expectReason(
    DurableRetryProcessorConfigurationException::MISSING_PROCESSOR,
    static fn () => new DurableRetryProcessorRegistry($missing),
    'one missing processor'
);
$partial = array_slice($processors(), 0, 2, true);
$expectReason(
    DurableRetryProcessorConfigurationException::INCOMPLETE_REGISTRY,
    static fn () => new DurableRetryProcessorRegistry($partial),
    'partial registry'
);
$unknownKey = $processors();
$unknownKey['unknown'] = $unknownKey[DurableRetryStage::RECONCILIATION];
unset($unknownKey[DurableRetryStage::RECONCILIATION]);
$expectReason(
    DurableRetryProcessorConfigurationException::UNKNOWN_STAGE,
    static fn () => new DurableRetryProcessorRegistry($unknownKey),
    'unknown keyed stage'
);
$nullProcessor = $processors();
$nullProcessor[DurableRetryStage::RECONCILIATION] = null;
$expectReason(
    DurableRetryProcessorConfigurationException::INVALID_PROCESSOR,
    static fn () => new DurableRetryProcessorRegistry($nullProcessor),
    'null processor'
);
$invalidProcessor = $processors();
$invalidProcessor[DurableRetryStage::RECONCILIATION] = new stdClass();
$expectReason(
    DurableRetryProcessorConfigurationException::INVALID_PROCESSOR,
    static fn () => new DurableRetryProcessorRegistry($invalidProcessor),
    'invalid processor'
);
$duplicate = array_values($processors());
$duplicate[] = $duplicate[0];
$expectReason(
    DurableRetryProcessorConfigurationException::DUPLICATE_PROCESSOR,
    static fn () => new DurableRetryProcessorRegistry($duplicate),
    'duplicate processor identity'
);
$duplicateStage = array_values($processors());
$duplicateStage[] = new RegistryProcessorDouble(
    DurableRetryStage::RECONCILIATION
);
$expectReason(
    DurableRetryProcessorConfigurationException::DUPLICATE_PROCESSOR,
    static fn () => new DurableRetryProcessorRegistry($duplicateStage),
    'duplicate declared stage'
);
$mismatch = $processors();
$mismatch[DurableRetryStage::RECONCILIATION] =
    new RegistryProcessorDouble(DurableRetryStage::BUSINESS_COMPLETION);
$expectReason(
    DurableRetryProcessorConfigurationException::PROCESSOR_STAGE_MISMATCH,
    static fn () => new DurableRetryProcessorRegistry($mismatch),
    'key and declared stage mismatch'
);
$unknownDeclaration = $processors();
$unknownDeclaration[0] = new RegistryProcessorDouble('unknown');
$expectReason(
    DurableRetryProcessorConfigurationException::UNKNOWN_STAGE,
    static fn () => new DurableRetryProcessorRegistry(
        array_values($unknownDeclaration)
    ),
    'processor declares unknown stage'
);
$expectReason(
    DurableRetryProcessorConfigurationException::UNKNOWN_STAGE,
    static fn () => $registry->resolve('unknown'),
    'resolve unknown stage'
);
$expectReason(
    DurableRetryProcessorConfigurationException::UNKNOWN_STAGE,
    static fn () => $registry->resolve(''),
    'resolve malformed stage'
);

$publicMethods = array_map(
    static fn (ReflectionMethod $method): string => $method->getName(),
    (new ReflectionClass(DurableRetryProcessorRegistry::class))
        ->getMethods(ReflectionMethod::IS_PUBLIC)
);
sort($publicMethods);
$assert(
    $publicMethods === ['__construct', 'resolve'],
    'registry exposes only construction and resolution'
);
foreach (['register', 'add', 'remove', 'replace', 'clear'] as $method) {
    $assert(! method_exists($registry, $method), 'no mutable ' . $method);
}
$assert(
    ! property_exists($registry, 'publicProcessors'),
    'internal map is not exposed'
);
foreach ($configured as $processor) {
    $assert($processor->processCalls === 0, 'resolution remains structural');
}

echo "durable retry processor registry: {$assertions} assertions\n";
