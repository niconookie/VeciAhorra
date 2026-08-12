<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$productionFiles = [
    'app/Modules/Orders/Contracts/DurableRetryActivationConfigurationSourceInterface.php',
    'app/Modules/Orders/Contracts/DurableRetryActivationPolicyInterface.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryActivationCohort.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryActivationConfiguration.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryDeterministicActivationPolicy.php',
    'app/Modules/Orders/Exceptions/DurableRetryActivationPolicyException.php',
];
$harnessFiles = [
    'tests/manual/durable-retry-activation-flag-policy-infrastructure-test.php',
    'tests/manual/durable-retry-activation-flag-policy-test.php',
    'tests/manual/durable-retry-activation-flag-policy-vectors-test.php',
];
$historicalHarness = 'tests/manual/durable-retry-schedule-infrastructure-test.php';
$allowedFiles = array_merge($productionFiles, $harnessFiles, [$historicalHarness]);
sort($allowedFiles);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;

    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach (array_merge($productionFiles, $harnessFiles) as $relativePath) {
    $assert(is_file($root . '/' . $relativePath), sprintf('Missing A2 file: %s', $relativePath));
}

$policyInterface = new ReflectionClass(
    VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationPolicyInterface::class
);
$sourceInterface = new ReflectionClass(
    VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationConfigurationSourceInterface::class
);
$configuration = new ReflectionClass(
    VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationConfiguration::class
);
$cohort = new ReflectionClass(
    VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationCohort::class
);
$policy = new ReflectionClass(
    VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryDeterministicActivationPolicy::class
);
$exception = new ReflectionClass(
    VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationPolicyException::class
);

$assert($policyInterface->isInterface(), 'Activation policy contract must be an interface.');
$assert($sourceInterface->isInterface(), 'Configuration source contract must be an interface.');
$assert($configuration->isFinal(), 'Configuration must be final.');
$assert($configuration->getConstructor()->isPrivate(), 'Configuration constructor must be private.');
$assert(
    count(array_filter(
        $configuration->getProperties(),
        static fn (ReflectionProperty $property): bool => !$property->isReadOnly()
    )) === 0,
    'Configuration properties must be readonly.'
);
$assert($cohort->isFinal(), 'Cohort must be final.');
$assert($cohort->getConstructor()->isPrivate(), 'Cohort must not be instantiated.');
$assert($policy->isFinal(), 'Policy must be final.');
$assert($policy->getProperty('source')->isReadOnly(), 'Policy source must be readonly.');
$assert($exception->isFinal(), 'Activation policy exception must be final.');
$assert($exception->isSubclassOf(InvalidArgumentException::class), 'Activation policy exception type changed.');

$policyMethod = $policyInterface->getMethod('allowsInitialTransfer');
$assert(count($policyInterface->getMethods()) === 1, 'Activation policy contract must expose one method.');
$assert((string) $policyMethod->getReturnType() === 'bool', 'Activation policy decision must return bool.');
$assert(count($policyMethod->getParameters()) === 1, 'Activation policy decision must accept one identity.');
$assert(
    (string) $policyMethod->getParameters()[0]->getType()
        === VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity::class,
    'Activation policy decision identity type changed.'
);

$sourceMethod = $sourceInterface->getMethod('snapshot');
$assert(count($sourceInterface->getMethods()) === 1, 'Configuration source contract must expose one method.');
$assert(count($sourceMethod->getParameters()) === 0, 'Configuration snapshot must accept no arguments.');
$assert(
    (string) $sourceMethod->getReturnType()
        === VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationConfiguration::class,
    'Configuration snapshot return type changed.'
);

$publicConfigurationMethods = array_map(
    static fn (ReflectionMethod $method): string => $method->getName(),
    $configuration->getMethods(ReflectionMethod::IS_PUBLIC)
);
sort($publicConfigurationMethods);
$assert(
    $publicConfigurationMethods === [
        'algorithmVersion',
        'disabled',
        'isDisabled',
        'isFullyEnabled',
        'percentage',
        'reconciliation',
        'stage',
    ],
    'Configuration public API changed.'
);

$assert($cohort->getConstant('ALGORITHM_VERSION') === 'sha256-24bit-mod100-v1', 'Algorithm version changed.');
$assert($cohort->getConstant('BUCKET_COUNT') === 100, 'Bucket count changed.');
$assert($policy->implementsInterface($policyInterface->getName()), 'Policy must implement its contract.');
$publicExceptionConstants = [];
foreach ($exception->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
    $publicExceptionConstants[$constant->getName()] = $constant->getValue();
}
$assert(
    $publicExceptionConstants === [
        'INVALID_PERCENTAGE' => 'invalid_percentage',
        'UNSUPPORTED_STAGE' => 'unsupported_stage',
        'UNSUPPORTED_ALGORITHM_VERSION' => 'unsupported_algorithm_version',
        'INVALID_CONFIGURATION_SNAPSHOT' => 'invalid_configuration_snapshot',
    ],
    'Activation policy exception catalog changed.'
);

$policySource = file_get_contents(
    $root . '/app/Modules/Orders/Domain/DurableRetry/DurableRetryDeterministicActivationPolicy.php'
);
$assert(
    substr_count($policySource, '$this->source->snapshot()') === 1,
    'Policy must obtain exactly one immutable snapshot per decision.'
);
$assert(
    strpos($policySource, 'UNSUPPORTED_STAGE') < strpos($policySource, 'DurableRetryActivationCohort::bucket'),
    'Unsupported stages must fail before hashing.'
);
$assert(
    strpos($policySource, 'isDisabled()') < strpos($policySource, 'DurableRetryActivationCohort::bucket')
        && strpos($policySource, 'isFullyEnabled()') < strpos($policySource, 'DurableRetryActivationCohort::bucket'),
    'Zero and one hundred percent must short-circuit before cohort calculation.'
);

$forbiddenPatterns = [
    '/\bwpdb\b/i',
    '/\bWP_Query\b/',
    '/\b(?:SELECT|INSERT|UPDATE|DELETE)\b/i',
    '/\b(?:fopen|file_put_contents|curl_|wp_remote_)\b/i',
    '/\b(?:time|microtime|random_int|mt_rand|rand)\s*\(/i',
    '/\$_(?:GET|POST|REQUEST|SERVER|ENV|COOKIE|SESSION|GLOBALS)\b/',
    '/\b(?:error_log|do_action|apply_filters|wp_schedule_|wp_next_scheduled)\b/i',
    '/\b(?:Repository|Executor|Scheduler|TransferService|Bootstrap)\b/',
];

foreach ($productionFiles as $relativePath) {
    $contents = file_get_contents($root . '/' . $relativePath);

    foreach ($forbiddenPatterns as $pattern) {
        $assert(
            preg_match($pattern, $contents) !== 1,
            sprintf('Forbidden infrastructure dependency in %s for %s', $relativePath, $pattern)
        );
    }
}

foreach ($allowedFiles as $relativePath) {
    $output = [];
    exec(
        'git -C ' . escapeshellarg($root)
            . ' ls-files --error-unmatch -- '
            . escapeshellarg($relativePath)
            . ' 2>&1',
        $output,
        $exit
    );
    $assert($exit === 0, "A2 path must remain versioned: {$relativePath}");
}

echo sprintf("OK durable retry activation flag policy infrastructure (%d assertions)\n", $assertions);
