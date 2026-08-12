<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    $root . '/app/Modules/Orders/Contracts/DurableRetryScheduleRepositoryInterface.php',
    $root . '/app/Modules/Orders/Repositories/DurableRetryScheduleRepository.php',
    $root . '/app/Modules/Orders/Domain/DurableRetry/DurableRetryPersistenceResult.php',
];
$source = implode("\n", array_map('file_get_contents', $paths));
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'as_schedule_single_action',
    'as_has_scheduled_action',
    'as_unschedule_action',
    'add_action',
    'wp_schedule_event',
    'DurableCompletionScheduler',
    'ActionScheduler',
    'current_time(',
    'UTC_TIMESTAMP(',
    'time(',
    'microtime(',
    'REPLACE ',
    'INSERT IGNORE',
    'ON DUPLICATE KEY UPDATE',
    'do_action',
    'wp_schedule_single_event',
    'register_rest_route',
] as $forbidden) {
    $assert(! str_contains($source, $forbidden), "repository excludes {$forbidden}");
}
$assert(
    substr_count($source, '->prepare(') === 5,
    'all repository statement families use prepare'
);
$assert(
    ! str_contains($source, 'last_error)') && ! str_contains($source, 'last_error .'),
    'database error text is not exposed'
);
$assert(
    str_contains($source, 'Config::TABLE_PREFIX'),
    'table name derives from central prefix'
);
$assert(
    str_contains($source, 'AND status = %s AND version = %d'),
    'CAS includes expected status and version'
);
$assert(
    str_contains($source, "return [\$column . ' IS NULL', []]")
        && str_contains($source, "'scheduled_action_id',"),
    'write-once NULL values are required atomically'
);
$assert(
    str_contains($source, "'id',")
        && str_contains($source, "'public_id',")
        && str_contains($source, "'generation',"),
    'CAS immutable identity is explicit'
);

$restricted = [
    'app/Core/Config.php',
    'app/Database/Builder/Blueprint.php',
    'app/Database/Builder/Column.php',
    'app/Database/Builder/TableBuilder.php',
    'app/Database/MigrationManager.php',
    'app/Database/Migrations/CreateDurableRetrySchedulesTable.php',
    'app/Database/Schemas/DurableRetryScheduleSchema.php',
];
exec(
    'git diff --name-only 8a7dbaa57b014e3afa78e162240a3e1cf14a6fcf -- '
        . implode(' ', array_map('escapeshellarg', $restricted)),
    $restrictedDiff,
    $exitCode
);
$assert($exitCode === 0 && $restrictedDiff === [], 'schema and builder remain unchanged');

$version = file_get_contents($root . '/app/Core/Config.php');
$assert(
    str_contains($version, "SCHEMA_VERSION = '0.24.0'"),
    'schema version remains 0.24.0'
);

echo "durable retry schedule repository infrastructure: {$assertions} assertions\n";
