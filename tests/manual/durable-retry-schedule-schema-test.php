<?php

declare(strict_types=1);

if (($argv[1] ?? null) === '--legacy-schema-snapshot') {
    $applicationRoot = rtrim((string) ($argv[2] ?? ''), '/\\');
    spl_autoload_register(static function (string $class) use ($applicationRoot): void {
        $prefix = 'VeciAhorra\\';
        if (! str_starts_with($class, $prefix)) {
            return;
        }
        $path = $applicationRoot . '/app/'
            . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
    $snapshot = [];
    $schemaFiles = glob($applicationRoot . '/app/Database/Schemas/*Schema.php') ?: [];
    sort($schemaFiles);
    foreach ($schemaFiles as $schemaFile) {
        if (basename($schemaFile) === 'DurableRetryScheduleSchema.php') {
            continue;
        }
        $class = 'VeciAhorra\\Database\\Schemas\\' . basename($schemaFile, '.php');
        $schema = new $class();
        $builder = VeciAhorra\Database\Builder\TableBuilder::make(
            'wp_va_' . $schema->name()
        );
        $schema->define($builder);
        $snapshot[basename($schemaFile)] = $builder->build(
            'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }
    echo json_encode($snapshot, JSON_THROW_ON_ERROR);
    exit(0);
}

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Builder\Column;
use VeciAhorra\Database\Schemas\DurableRetryScheduleSchema;
use VeciAhorra\Core\Config;
use VeciAhorra\Database\MigrationManager;
use VeciAhorra\Database\Migrations\CreateDurableRetrySchedulesTable;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$schema = new DurableRetryScheduleSchema();
$assert($schema->name() === 'durable_retry_schedules', 'logical table name');

$build = static function () use ($schema): string {
    $builder = TableBuilder::make('wp_va_' . $schema->name());
    $schema->define($builder);

    return $builder->build('DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
};

$sql = $build();
$assert($sql === $build(), 'schema generation is idempotent');
$assert(
    str_starts_with($sql, 'CREATE TABLE wp_va_durable_retry_schedules ('),
    'physical table name'
);
$assert(
    str_ends_with(
        $sql,
        'ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
    ),
    'environment charset and collation'
);

preg_match_all(
    '/^([a-z_]+) (?:BIGINT|INT|TINYINT|CHAR|VARCHAR|DATETIME)/m',
    $sql,
    $matches
);
$columns = $matches[1];
$expectedColumns = [
    'id',
    'public_id',
    'stage',
    'subject_id',
    'completion_id',
    'generation',
    'attempt_number',
    'scheduled_for',
    'scheduled_action_id',
    'dispatch_token_hash',
    'status',
    'active_slot',
    'version',
    'reason_code',
    'dispatched_at',
    'claimed_at',
    'consumed_at',
    'terminal_at',
    'created_at',
    'updated_at',
];
$assert($columns === $expectedColumns, 'exact 20 columns in normative order');

$definitions = [
    'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
    'public_id CHAR(64) NOT NULL',
    'stage VARCHAR(40) NOT NULL',
    'subject_id BIGINT UNSIGNED NOT NULL',
    'completion_id BIGINT UNSIGNED NULL DEFAULT NULL',
    'generation INT UNSIGNED NOT NULL',
    'attempt_number INT UNSIGNED NOT NULL',
    'scheduled_for DATETIME NOT NULL',
    'scheduled_action_id BIGINT UNSIGNED NULL DEFAULT NULL',
    'dispatch_token_hash CHAR(64) NOT NULL',
    'status VARCHAR(24) NOT NULL',
    'active_slot TINYINT UNSIGNED NULL DEFAULT NULL',
    'version INT UNSIGNED NOT NULL',
    'reason_code VARCHAR(50) NOT NULL',
    'dispatched_at DATETIME NULL DEFAULT NULL',
    'claimed_at DATETIME NULL DEFAULT NULL',
    'consumed_at DATETIME NULL DEFAULT NULL',
    'terminal_at DATETIME NULL DEFAULT NULL',
    'created_at DATETIME NOT NULL',
    'updated_at DATETIME NOT NULL',
];
foreach ($definitions as $definition) {
    $assert(str_contains($sql, "\n{$definition}"), "column definition: {$definition}");
}
$assert(! str_contains($sql, 'CURRENT_TIMESTAMP'), 'no automatic timestamp defaults');
$assert(! preg_match('/FOREIGN KEY|\\bCHECK\\b/i', $sql), 'no foreign keys or checks');

$expectedIndexes = [
    'PRIMARY KEY (id)',
    'UNIQUE KEY durable_retry_public_unique (public_id)',
    'UNIQUE KEY durable_retry_identity_unique (stage, subject_id, generation)',
    'UNIQUE KEY durable_retry_active_unique (stage, subject_id, active_slot)',
    'UNIQUE KEY durable_retry_action_unique (scheduled_action_id)',
    'KEY durable_retry_recovery_index (status, updated_at)',
    'KEY durable_retry_retention_index (status, terminal_at)',
    'KEY durable_retry_completion_read_index (stage, completion_id, status)',
];
preg_match_all('/^(?:PRIMARY KEY|UNIQUE KEY|KEY) .+$/m', $sql, $indexMatches);
$indexes = array_map(
    static fn (string $index): string => rtrim(trim($index), ','),
    $indexMatches[0]
);
$assert($indexes === $expectedIndexes, 'exact normative indexes');
$assert(
    str_contains(
        $sql,
        'UNIQUE KEY durable_retry_active_unique (stage, subject_id, active_slot)'
    ),
    'one active slot is unique'
);
$assert(
    str_contains($sql, 'active_slot TINYINT UNSIGNED NULL DEFAULT NULL'),
    'inactive histories use nullable unique slot'
);

$migration = file_get_contents(
    dirname(__DIR__, 2)
    . '/app/Database/Migrations/CreateDurableRetrySchedulesTable.php'
);
$assert(substr_count($migration, 'dbDelta(') === 1, 'migration delegates once to dbDelta');
$assert(! preg_match('/\\b(?:INSERT|UPDATE|DELETE|SELECT)\\b/i', $migration), 'no data operations');
$assert(! str_contains($migration, 'ActionScheduler'), 'no Action Scheduler access');
$registeredMigrations = (new ReflectionMethod(MigrationManager::class, 'migrations'))
    ->invoke(null);
$assert(
    count(array_filter(
        $registeredMigrations,
        static fn (object $item): bool => $item instanceof CreateDurableRetrySchedulesTable
    )) === 1,
    'migration registered exactly once'
);
$assert(
    is_string(Config::SCHEMA_VERSION)
        && version_compare(Config::SCHEMA_VERSION, '0.24.0', '>='),
    'schema version remains compatible with durable retry schedule schema'
);

$columnCases = [
    [(new Column('nullable_value', 'VARCHAR(20)'))->nullable(), 'nullable_value VARCHAR(20) NULL'],
    [
        (new Column('nullable_default', 'VARCHAR(20)'))->nullable()->defaultNull(),
        'nullable_default VARCHAR(20) NULL DEFAULT NULL',
    ],
    [new Column('required_value', 'VARCHAR(20)'), 'required_value VARCHAR(20) NOT NULL'],
    [new Column('fixed_hash', 'CHAR(64)'), 'fixed_hash CHAR(64) NOT NULL'],
    [new Column('variable_name', 'VARCHAR(40)'), 'variable_name VARCHAR(40) NOT NULL'],
    [new Column('counter', 'INT UNSIGNED'), 'counter INT UNSIGNED NOT NULL'],
    [new Column('entity_id', 'BIGINT UNSIGNED'), 'entity_id BIGINT UNSIGNED NOT NULL'],
    [new Column('slot', 'TINYINT UNSIGNED'), 'slot TINYINT UNSIGNED NOT NULL'],
    [new Column('occurred_at', 'DATETIME'), 'occurred_at DATETIME NOT NULL'],
    [
        (new Column('id', 'BIGINT UNSIGNED'))->autoIncrement()->primary(),
        'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
    ],
];
foreach ($columnCases as [$column, $expected]) {
    $assert($column->toSql() === $expected, "column SQL remains deterministic: {$expected}");
}
$assert(
    ! preg_match(
        "/DEFAULT (?:''|'0'|NULL)/",
        (new Column('plain', 'VARCHAR(20)'))->toSql()
    ),
    'plain columns receive no silent default'
);
foreach ([
    static fn () => (new Column('invalid', 'VARCHAR(20)'))->defaultNull(),
    static fn () => (new Column('invalid', 'VARCHAR(20)'))
        ->nullable()->defaultNull()->default('value'),
    static fn () => (new Column('invalid', 'VARCHAR(20)'))
        ->nullable()->default('value')->defaultNull(),
] as $invalidColumn) {
    try {
        $invalidColumn();
        $assert(false, 'contradictory default combination rejected');
    } catch (InvalidArgumentException) {
        $assert(true, 'contradictory default combination rejected');
    }
}

$indexBuilder = TableBuilder::make('wp_va_builder_contract');
$indexSql = $indexBuilder
    ->id()
    ->string('public_id', 64)
    ->string('stage', 40)
    ->bigIntegerUnsigned('subject_id')
    ->unique('public_id', 'builder_public_unique')
    ->unique(['stage', 'subject_id'], 'builder_identity_unique')
    ->index(['stage', 'subject_id'], 'builder_stage_subject_index')
    ->build('DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$expectedIndexLines = [
    'PRIMARY KEY (id)',
    'UNIQUE KEY builder_public_unique (public_id)',
    'UNIQUE KEY builder_identity_unique (stage, subject_id)',
    'KEY builder_stage_subject_index (stage, subject_id)',
];
preg_match_all(
    '/^(?:PRIMARY KEY|UNIQUE KEY|KEY) .+$/m',
    $indexSql,
    $builderIndexMatches
);
$actualIndexLines = array_map(
    static fn (string $line): string => rtrim(trim($line), ','),
    $builderIndexMatches[0]
);
$assert($actualIndexLines === $expectedIndexLines, 'builder index order and syntax');
$assert(
    count($actualIndexLines) === count(array_unique($actualIndexLines)),
    'builder emits no duplicate indexes'
);
$assert(! str_contains($indexSql, ",\n)"), 'builder emits no trailing comma');

echo "durable retry schedule schema: {$assertions} assertions\n";
