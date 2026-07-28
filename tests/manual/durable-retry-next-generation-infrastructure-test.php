<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$interface = file_get_contents(
    $root . '/app/Modules/Orders/Contracts/DurableRetryScheduleRepositoryInterface.php'
);
$repositoryPath = $root . '/app/Modules/Orders/Repositories/DurableRetryScheduleRepository.php';
$repository = file_get_contents($repositoryPath);
$result = file_get_contents(
    $root . '/app/Modules/Orders/Domain/DurableRetry/DurableRetryNextGenerationPersistenceResult.php'
);
$source = $interface . "\n" . $repository . "\n" . $result;
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    str_contains($interface, 'function supersedeAndCreateNextGeneration('),
    'method exists in existing interface'
);
$assert(
    str_contains($repository, 'function supersedeAndCreateNextGeneration('),
    'method exists in existing repository'
);
$repositories = glob($root . '/app/Modules/Orders/Repositories/*DurableRetry*Repository.php');
$assert(count($repositories) === 1, 'no second durable retry repository');
$assert(substr_count($repository, "'START TRANSACTION'") === 1, 'single transaction boundary');
$method = substr($repository, strpos($repository, 'public function supersedeAndCreateNextGeneration('));
$method = substr($method, 0, strpos($method, 'private function validateNextGenerationRequest('));
$assert(strpos($method, "'UPDATE '") < strpos($method, '$this->insertStatement'), 'update precedes insert');
$assert(str_contains($method, '$this->rollback()'), 'failures after begin roll back');
$assert(str_contains($method, 'findById($claimed->id())'), 'historical evidence reread');
$assert(str_contains($method, 'findById($successorId)'), 'successor evidence reread');
$assert(str_contains($method, "'COMMIT'"), 'commit explicit');
$assert(str_contains($repository, "'active_slot' => 1"), 'successor acquires active slot');
$assert(str_contains($repository, "'active_slot' => null"), 'historical row releases active slot');
$assert(str_contains($repository, "'scheduled_action_id' => null"), 'successor has no external action');
$assert(str_contains($repository, "'claimed_at' => null"), 'successor has no claim');
$assert(str_contains($repository, "'version' => 1"), 'successor starts at version one');
$assert(str_contains($repository, 'random_bytes(32)'), 'successor identities are newly generated');
$assert(! str_contains($repository, 'decision->nextAttemptNumber() + 1'), 'attempt is not incremented twice');
$assert(
    ! str_contains($repository, "'attempt_number' => \$decision->nextGeneration()"),
    'attempt not inferred from generation'
);
foreach ([
    'ActionScheduler',
    'as_schedule_',
    'DurableRetryExternalScheduleCoordinator',
    'add_action',
    'do_action',
    'register_rest_route',
    'current_time(',
    'wp_date(',
    'time(',
    'microtime(',
    "DateTimeImmutable('now",
    'sleep(',
    'wp_remote_',
] as $forbidden) {
    $assert(! str_contains($source, $forbidden), "excludes {$forbidden}");
}
$sqlFiles = [];
foreach ([
    $root . '/app/Modules/Orders/Contracts/DurableRetryScheduleRepositoryInterface.php',
    $root . '/app/Modules/Orders/Domain/DurableRetry/DurableRetryNextGenerationPersistenceResult.php',
] as $path) {
    if (preg_match('/\\b(?:SELECT|INSERT|UPDATE|DELETE)\\b/i', file_get_contents($path))) {
        $sqlFiles[] = $path;
    }
}
$assert($sqlFiles === [], 'SQL remains inside concrete repository');
$assert(! str_contains($source, 'actionscheduler_'), 'no Action Scheduler internal tables');
$assert(
    ! str_contains($result, 'last_error')
        && ! str_contains($result, 'Throwable')
        && ! str_contains($result, 'Exception $'),
    'result exposes no engine internals'
);

$restricted = [
    'app/Core/Config.php',
    'app/Database/Migrations',
    'app/Database/Schemas',
    'app/Modules/Orders/Services',
    'app/Modules/Orders/Infrastructure',
    'app/Modules/Payment',
    'app/Modules/Payments',
    'app/Modules/Delivery',
    'app/Modules/Fulfillment',
    'docs',
];
exec(
    'git diff --name-only HEAD -- '
        . implode(' ', array_map('escapeshellarg', $restricted)),
    $restrictedDiff,
    $exitCode
);
$assert(
    $exitCode === 0
        && $restrictedDiff === [
            'app/Modules/Orders/Services/DurableRetryExecutor.php',
        ],
    'restricted paths limited to nullable executor contract'
);
$assert(
    str_contains(
        file_get_contents($root . '/app/Core/Config.php'),
        "SCHEMA_VERSION = '0.24.0'"
    ),
    'schema version remains 0.24.0'
);

echo "durable retry next generation infrastructure: {$assertions} assertions\n";
