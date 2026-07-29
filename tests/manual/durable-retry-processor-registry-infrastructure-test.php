<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Modules\Orders\Contracts\DurableRetryStageProcessorResolverInterface;
use VeciAhorra\Modules\Orders\Exceptions\DurableRetryProcessorConfigurationException;
use VeciAhorra\Modules\Orders\Services\DurableRetryProcessorRegistry;

$root = dirname(__DIR__, 2);
$interfacePath = $root
    . '/app/Modules/Orders/Contracts/'
    . 'DurableRetryStageProcessorResolverInterface.php';
$registryPath = $root
    . '/app/Modules/Orders/Services/DurableRetryProcessorRegistry.php';
$exceptionPath = $root
    . '/app/Modules/Orders/Exceptions/'
    . 'DurableRetryProcessorConfigurationException.php';
$functionalPath = $root
    . '/tests/manual/durable-retry-processor-registry-test.php';
$infrastructurePath = __FILE__;
$files = [
    $interfacePath,
    $registryPath,
    $exceptionPath,
    $functionalPath,
    $infrastructurePath,
];

$assertions = 0;
$assert = static function (bool $condition, string $message) use (
    &$assertions
): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

foreach ($files as $file) {
    $assert(is_file($file), 'new file exists: ' . basename($file));
}

$interface = file_get_contents($interfacePath);
$registry = file_get_contents($registryPath);
$exception = file_get_contents($exceptionPath);
$assert(is_string($interface), 'resolver source readable');
$assert(is_string($registry), 'registry source readable');
$assert(is_string($exception), 'exception source readable');
$assert(
    is_subclass_of(
        DurableRetryProcessorRegistry::class,
        DurableRetryStageProcessorResolverInterface::class
    ),
    'registry implements resolver'
);
$resolverMethod = new ReflectionMethod(
    DurableRetryStageProcessorResolverInterface::class,
    'resolve'
);
$assert(
    (string) $resolverMethod->getReturnType()
        === 'VeciAhorra\\Modules\\Orders\\Contracts\\'
            . 'DurableRetryStageProcessorInterface',
    'resolver returns common processor contract'
);
$assert(
    ! $resolverMethod->getReturnType()?->allowsNull(),
    'resolver return is not nullable'
);
$registryMethods = array_map(
    static fn (ReflectionMethod $method): string => $method->getName(),
    (new ReflectionClass(DurableRetryProcessorRegistry::class))
        ->getMethods(ReflectionMethod::IS_PUBLIC)
);
sort($registryMethods);
$assert(
    $registryMethods === ['__construct', 'resolve'],
    'registry public API is closed'
);
$assert(
    is_subclass_of(
        DurableRetryProcessorConfigurationException::class,
        RuntimeException::class
    ),
    'configuration exception is typed'
);

foreach (['register', 'add', 'remove', 'replace', 'clear'] as $method) {
    $assert(
        ! str_contains($registry, 'function ' . $method . '('),
        'no mutable method ' . $method
    );
}
foreach ([
    'DurableRetryReconciliationProcessor',
    'DurableRetryBusinessCompletionProcessor',
    'DurableRetryDeliveryCompletionProcessor',
    'DurableRetryFulfillmentProcessor',
] as $concrete) {
    $assert(
        ! str_contains($registry, 'new ' . $concrete),
        'registry does not construct ' . $concrete
    );
}
foreach ([
    'DurableRetryExecutor',
    'DurableRetryExternalScheduler',
    'ActionScheduler',
    'as_schedule_',
    'add_action',
    'do_action',
    'wp_schedule_',
    '$wpdb',
    'SELECT ',
    'INSERT ',
    'UPDATE ',
    'DELETE ',
    'sleep(',
    'usleep(',
    'ReflectionClass',
    'class_exists',
    'Container',
    'global ',
    'subject_id',
    'completion_id',
    'generation',
    'backoff',
] as $forbidden) {
    $assert(
        ! str_contains($registry, $forbidden),
        'registry forbids ' . $forbidden
    );
}
$assert(! str_contains($registry, '->process('), 'registry executes no work');
$assert(
    str_contains($registry, 'DurableRetryStage::all()'),
    'registry reuses authoritative catalog'
);
$assert(
    substr_count($registry, 'public function') === 2,
    'constructor and resolve are the only public methods'
);
$assert(
    str_contains($registry, 'private readonly array $processors'),
    'internal map is immutable'
);
foreach ([
    'UNKNOWN_STAGE',
    'MISSING_PROCESSOR',
    'DUPLICATE_PROCESSOR',
    'PROCESSOR_STAGE_MISMATCH',
    'INVALID_PROCESSOR',
    'INCOMPLETE_REGISTRY',
    'INVALID_REGISTRY_CONFIGURATION',
] as $reason) {
    $assert(str_contains($exception, $reason), 'closed reason ' . $reason);
}

$certifiedPaths = [
    'app/Modules/Orders/Contracts/'
        . 'DurableRetryStageProcessorResolverInterface.php',
    'app/Modules/Orders/Exceptions/'
        . 'DurableRetryProcessorConfigurationException.php',
    'app/Modules/Orders/Services/DurableRetryProcessorRegistry.php',
    'tests/manual/durable-retry-processor-registry-infrastructure-test.php',
    'tests/manual/durable-retry-processor-registry-test.php',
];
$pathArguments = implode(
    ' ',
    array_map('escapeshellarg', $certifiedPaths)
);
$tracked = [];
exec('git ls-files -- ' . $pathArguments, $tracked, $trackedCode);
$untracked = [];
exec(
    'git ls-files --others --exclude-standard -- ' . $pathArguments,
    $untracked,
    $untrackedCode
);
$unstaged = [];
exec('git diff --name-only -- ' . $pathArguments, $unstaged, $unstagedCode);
$staged = [];
exec(
    'git diff --cached --name-only -- ' . $pathArguments,
    $staged,
    $stagedCode
);
sort($certifiedPaths);
sort($tracked);
sort($untracked);
$assert(
    $trackedCode === 0
        && $untrackedCode === 0
        && $unstagedCode === 0
        && $stagedCode === 0,
    'certified scope inspections succeed'
);
$assert(
    ($tracked === $certifiedPaths && $untracked === [])
        || ($tracked === [] && $untracked === $certifiedPaths),
    'certified scope is coherently tracked or untracked'
);
$assert($unstaged === [], 'certified scope has no unstaged changes');
$assert($staged === [], 'certified scope has no staged changes');

echo "durable retry processor registry infrastructure: "
    . "{$assertions} assertions\n";
