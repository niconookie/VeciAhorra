<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationPolicyInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialAuthorityProducerInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialTransferAuthorityInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacyExclusionInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialAuthorityProductionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialAuthorityProducer;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (
    &$assertions
): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$root = dirname(__DIR__, 2);
$allowed = [
    'app/Modules/Orders/Contracts/DurableRetryInitialAuthorityProducerInterface.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialAuthorityProductionResult.php',
    'app/Modules/Orders/Services/DurableRetryInitialAuthorityProducer.php',
    'tests/manual/durable-retry-initial-authority-producer-test.php',
    'tests/manual/durable-retry-initial-authority-producer-infrastructure-test.php',
];
foreach ($allowed as $file) {
    $assert(is_file($root . '/' . $file), "Allowlisted file missing: {$file}");
}

$git = static function (string $arguments) use ($root): array {
    $output = [];
    $exit = 0;
    exec('git -C ' . escapeshellarg($root) . ' ' . $arguments . ' 2>&1', $output, $exit);

    return [$exit, array_values(array_filter(
        array_map('trim', $output),
        static fn (string $line): bool => $line !== ''
    ))];
};
[$exit, $staged] = $git('diff --cached --name-only');
$assert($exit === 0 && $staged === [], 'Staging must be empty.');
[$exit, $tracked] = $git('diff --name-only');
$assert($exit === 0 && $tracked === [], 'Tracked files must remain intact.');
[$exit, $untracked] = $git('ls-files --others --exclude-standard');
$assert($exit === 0, 'Untracked inventory must be readable.');
$actual = array_values(array_intersect($untracked, $allowed));
sort($actual);
$expected = $allowed;
sort($expected);
$assert($actual === $expected, 'Exactly the five A5 files are untracked.');
$candidates = array_values(array_filter(
    $untracked,
    static fn (string $path): bool =>
        str_contains($path, 'InitialAuthorityProducer')
        || str_contains($path, 'InitialAuthorityProductionResult')
        || str_contains($path, 'initial-authority-producer')
));
$assert(count($candidates) === 5, 'A sixth A5 file must not exist.');

$interface = new ReflectionClass(
    DurableRetryInitialAuthorityProducerInterface::class
);
$service = new ReflectionClass(DurableRetryInitialAuthorityProducer::class);
$result = new ReflectionClass(
    DurableRetryInitialAuthorityProductionResult::class
);
$assert($interface->isInterface(), 'A5 contract is an interface.');
$assert($service->isFinal(), 'A5 service is final.');
$assert($result->isFinal(), 'A5 result is final.');
$assert(
    $service->implementsInterface(
        DurableRetryInitialAuthorityProducerInterface::class
    ),
    'A5 service implements its exact interface.'
);
$constructor = $service->getConstructor();
$assert($constructor !== null, 'A5 constructor exists.');
$parameters = $constructor->getParameters();
$assert(count($parameters) === 3, 'A5 constructor has exactly three dependencies.');
$expectedTypes = [
    DurableRetryLegacyExclusionInterface::class,
    DurableRetryActivationPolicyInterface::class,
    DurableRetryInitialTransferAuthorityInterface::class,
];
foreach ($parameters as $index => $parameter) {
    $assert(! $parameter->isOptional(), "Dependency {$index} is required.");
    $assert(! $parameter->isVariadic(), "Dependency {$index} is not variadic.");
    $assert(
        (string) $parameter->getType() === $expectedTypes[$index],
        "Dependency {$index} type is exact."
    );
}
$method = $service->getMethod('produceReconciliation');
$assert($method->isPublic(), 'A5 operation is public.');
$assert(count($method->getParameters()) === 1, 'A5 operation has one input.');
$assert(
    (string) $method->getParameters()[0]->getType()
        === DurableRetryInitialTransferRequest::class,
    'A5 request type is exact.'
);
$assert(
    (string) $method->getReturnType()
        === DurableRetryInitialAuthorityProductionResult::class,
    'A5 return type is exact.'
);
$interfaceMethod = $interface->getMethod('produceReconciliation');
$assert(
    (string) $interfaceMethod->getParameters()[0]->getType()
        === DurableRetryInitialTransferRequest::class
        && (string) $interfaceMethod->getReturnType()
            === DurableRetryInitialAuthorityProductionResult::class,
    'Interface signature is exact.'
);

$stateConstants = [
    'LEGACY_ALLOWED',
    'LEGACY_IN_FLIGHT',
    'DURABLE_EXISTING',
    'DURABLE_CREATED',
    'DURABLE_CONVERGED',
    'FUNCTIONALLY_INELIGIBLE',
    'AUTHORITY_INDETERMINATE',
    'DURABLE_INCONSISTENCY',
    'CONFIGURATION_INVALID',
    'PERSISTENCE_ERROR',
    'OUTCOME_UNCERTAIN',
    'OPERATIONAL_FAILURE',
];
foreach ($stateConstants as $constant) {
    $assert($result->hasConstant($constant), "Result state exists: {$constant}");
}
$assert(count($stateConstants) === 12, 'Result catalog contains twelve states.');
$assert($result->getConstructor()?->isPrivate(), 'Result constructor is private.');
foreach ([
    'state',
    'reason',
    'authorityResult',
    'transferResult',
    'permitsLegacyProduction',
    'durableAuthorityConfirmed',
    'requiresRecovery',
] as $operation) {
    $assert($result->getMethod($operation)->isPublic(), "Result API: {$operation}");
}

$productionFiles = array_slice($allowed, 0, 3);
$productionSource = implode('', array_map(
    static fn (string $file): string =>
        (string) file_get_contents($root . '/' . $file),
    $productionFiles
));
$tokens = token_get_all($productionSource);
$identifiers = [];
$strings = [];
foreach ($tokens as $token) {
    if (! is_array($token)) {
        continue;
    }
    if ($token[0] === T_STRING) {
        $identifiers[] = strtolower($token[1]);
    }
    if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
        $strings[] = strtolower(trim($token[1], "'\""));
    }
}
foreach ([
    'as_schedule',
    'as_get',
    'as_unschedule',
    'add_action',
    'do_action',
    'apply_filters',
    'sleep',
    'usleep',
    'wpdb',
] as $forbiddenIdentifier) {
    $assert(
        count(array_filter(
            $identifiers,
            static fn (string $identifier): bool =>
                str_starts_with($identifier, $forbiddenIdentifier)
        )) === 0,
        "Forbidden identifier absent: {$forbiddenIdentifier}"
    );
}
foreach ([
    'select ',
    'insert ',
    'update ',
    'delete ',
    'start transaction',
    'commit',
    'rollback',
] as $sql) {
    $assert(
        count(array_filter(
            $strings,
            static fn (string $literal): bool => str_contains($literal, $sql)
        )) === 0,
        "SQL literal absent: {$sql}"
    );
}
foreach ([
    'Scheduler',
    'Coordinator',
    'Executor',
    'Callback',
    'Registry',
    'Processor',
    'LegacyProducer',
    'Logger',
    'Metrics',
    'Dispatcher',
] as $forbiddenType) {
    $assert(
        ! preg_match('/\\\\?' . $forbiddenType . '\\b/i', $productionSource),
        "Forbidden production dependency absent: {$forbiddenType}"
    );
}
$assert(
    ! preg_match('/\\b(?:for|foreach|while|do)\\s*\\(/', $productionSource),
    'Production A5 has no loops.'
);
$assert(
    substr_count($productionSource, '->classify(') === 1,
    'A3 has one call site.'
);
$assert(
    substr_count($productionSource, '->allowsInitialTransfer(') === 1,
    'A2 has one call site.'
);
$assert(
    substr_count($productionSource, '->transferReconciliation(') === 1,
    'A4 has one call site.'
);
$a3 = strpos($productionSource, '->classify(');
$a2 = strpos($productionSource, '->allowsInitialTransfer(');
$a4 = strpos($productionSource, '->transferReconciliation(');
$assert($a3 < $a2 && $a2 < $a4, 'Dependency call sites are ordered A3, A2, A4.');

$artifactEntries = iterator_to_array(new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $root . '/artifacts',
        FilesystemIterator::SKIP_DOTS
    )
));
$assert(
    count(array_filter(
        $artifactEntries,
        static fn (SplFileInfo $entry): bool => $entry->isFile()
    )) === 504,
    'Artifacts inventory remains at 504 files.'
);

echo "OK durable retry initial authority producer infrastructure ({$assertions} assertions)\n";
