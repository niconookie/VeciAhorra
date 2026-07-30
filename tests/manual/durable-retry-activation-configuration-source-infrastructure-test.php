<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$newFiles = [
    'app/Modules/Orders/Contracts/DurableRetryActivationConfigurationValueReaderInterface.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryActivationConfigurationValue.php',
    'app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryProductionActivationConfigurationSource.php',
    'app/Modules/Orders/Infrastructure/DurableRetry/WordPressOptionDurableRetryActivationConfigurationValueReader.php',
    'app/Modules/Orders/Exceptions/DurableRetryActivationConfigurationSourceException.php',
    'tests/manual/durable-retry-activation-configuration-source-test.php',
    'tests/manual/durable-retry-activation-configuration-wordpress-test.php',
    'tests/manual/durable-retry-activation-configuration-source-infrastructure-test.php',
];
$modifiedFiles = [
    'tests/manual/durable-retry-schedule-infrastructure-test.php',
];
$productionFiles = array_slice($newFiles, 0, 5);
foreach ($newFiles as $file) {
    $assert(is_file($root . '/' . $file), "{$file} must exist.");
}

$sourcePath = $root . '/' . $productionFiles[2];
$readerPath = $root . '/' . $productionFiles[3];
$source = file_get_contents($sourcePath);
$reader = file_get_contents($readerPath);
$productionSource = implode("\n", array_map(
    static fn (string $file): string => file_get_contents($root . '/' . $file),
    $productionFiles
));

require_once $root . '/vendor/autoload.php';

$sourceReflection = new ReflectionClass(
    VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryProductionActivationConfigurationSource::class
);
$readerReflection = new ReflectionClass(
    VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\WordPressOptionDurableRetryActivationConfigurationValueReader::class
);
$valueReflection = new ReflectionClass(
    VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationConfigurationValue::class
);
$exceptionReflection = new ReflectionClass(
    VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationConfigurationSourceException::class
);

foreach ([$sourceReflection, $readerReflection, $valueReflection, $exceptionReflection] as $reflection) {
    $assert($reflection->isFinal(), "{$reflection->getName()} must be final.");
}
$assert(
    $sourceReflection->implementsInterface(
        VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationConfigurationSourceInterface::class
    ),
    'Source must implement the A2 contract.'
);
$assert(
    $readerReflection->implementsInterface(
        VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationConfigurationValueReaderInterface::class
    ),
    'WordPress reader must implement its contract.'
);
foreach ([$sourceReflection, $valueReflection, $exceptionReflection] as $reflection) {
    foreach ($reflection->getProperties() as $property) {
        if ($property->getDeclaringClass()->getName() !== $reflection->getName()) {
            continue;
        }
        $assert($property->isReadOnly(), "{$property->getName()} must be readonly.");
    }
}

$assert(substr_count($source, '$this->reader->read()') === 1, 'Source must contain one reader call.');
$assert(substr_count($reader, 'get_option(self::OPTION_NAME, $absentSentinel)') === 1, 'Reader must contain one get_option call.');

foreach ([
    'Config::',
    'getenv(',
    '$_ENV',
    '$_SERVER',
    'get_site_option(',
    'add_option(',
    'update_option(',
    'delete_option(',
    'set_transient(',
    'get_transient(',
    'add_action(',
    'add_filter(',
    'apply_filters(',
    '$wpdb',
    'SELECT ',
    'INSERT ',
    'UPDATE ',
    'DELETE ',
    'file_get_contents(',
    'file_put_contents(',
    'curl_',
    'wp_remote_',
    'time(',
    'microtime(',
    'ActionScheduler',
    'as_schedule_',
    'schedule',
    'batch',
    'transfer',
    'rollout',
    'error_log(',
] as $forbidden) {
    $assert(
        ! str_contains($productionSource, $forbidden),
        "Production A2.1 source must exclude {$forbidden}."
    );
}

$status = [];
exec('git -C ' . escapeshellarg($root) . ' status --porcelain', $status);
$changed = [];
foreach ($status as $line) {
    $path = substr($line, 3);
    if (in_array($path, array_merge($newFiles, $modifiedFiles), true)) {
        $changed[] = $path;
    }
}
sort($changed);
$expected = array_merge($newFiles, $modifiedFiles);
sort($expected);
$assert($changed === $expected, 'All nine allowlisted files must be the only A2.1 changes.');

echo "OK durable retry activation configuration infrastructure ({$assertions} assertions)\n";
