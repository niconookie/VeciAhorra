<?php

declare(strict_types=1);

if (! class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
    }
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$repositoryPath = $root . '/app/Modules/Orders/Repositories/DurableRetryLegacyAuthorityRepository.php';
$allowed = [
    'app/Modules/Orders/Repositories/DurableRetryLegacyAuthorityRepository.php',
    'tests/manual/durable-retry-legacy-authority-repository-test.php',
    'tests/manual/durable-retry-legacy-authority-repository-mysql-test.php',
    'tests/manual/durable-retry-legacy-authority-infrastructure-test.php',
];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

foreach ($allowed as $file) {
    $assert(is_file($root . '/' . $file), "{$file} must exist.");
}

$reflection = new ReflectionClass(
    VeciAhorra\Modules\Orders\Repositories\DurableRetryLegacyAuthorityRepository::class
);
$assert($reflection->isFinal(), 'Repository must be final.');
$assert(
    $reflection->implementsInterface(
        VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacyExclusionInterface::class
    ),
    'Repository must implement the A1 exclusion contract.'
);
$constructor = $reflection->getConstructor();
$assert($constructor !== null && $constructor->isPublic(), 'Constructor must be public.');
$assert(count($constructor->getParameters()) === 1, 'Constructor must have one dependency.');
$assert((string) $constructor->getParameters()[0]->getType() === 'wpdb', 'Dependency must be wpdb.');

$source = file_get_contents($repositoryPath);
$assert(substr_count($source, 'get_results(') === 1, 'Repository must have one physical read site.');
$assert(str_contains($source, 'Config::TABLE_PREFIX'), 'Repository must use the plugin table prefix authority.');
$assert(! str_contains($source, 'veciahorra_durable_retry_schedules'), 'Incorrect physical name must be absent.');
$assert(! str_contains($source, 'absent'), 'A3 must not introduce absent state.');

foreach ([
    'INSERT ',
    'UPDATE ',
    'DELETE ',
    'information_schema',
    'SHOW TABLES',
    'add_action',
    'do_action',
    'apply_filters',
    'error_log',
    'allowsInitialTransfer',
    'snapshot()',
    'transferReconciliation',
    'as_schedule_',
    'as_unschedule_',
    'scheduleReconciliation',
    'associateScheduledAction',
    'supersedeAndCreateNextGeneration',
    'transition(',
] as $forbidden) {
    $assert(! str_contains($source, $forbidden), "Repository must exclude {$forbidden}.");
}

foreach ($allowed as $file) {
    $output = [];
    exec(
        'git -C ' . escapeshellarg($root)
            . ' ls-files --error-unmatch -- '
            . escapeshellarg($file)
            . ' 2>&1',
        $output,
        $exit
    );
    $assert($exit === 0, "A3 path must remain versioned: {$file}");
}

echo "OK durable retry legacy authority infrastructure ({$assertions} assertions)\n";
