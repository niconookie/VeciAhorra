<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$a11LocalCoexistencePaths = ['app/Core/Application.php', 'app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php', 'app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php', 'tests/manual/durable-completion-orchestration-test.php', 'tests/manual/support/durable-retry-a11-coordinator.php', 'tests/manual/support/durable-retry-a11-runtime-capture-contract.php', 'tests/manual/durable-retry-a11-runtime-capture-test.php', 'tests/manual/durable-retry-a11-runtime-capture-infrastructure-test.php'];
$a11HistoricalMaintenancePaths = ['tests/manual/durable-retry-action-callback-infrastructure-test.php', 'tests/manual/durable-retry-action-hook-registrar-infrastructure-test.php', 'tests/manual/durable-retry-business-completion-processor-infrastructure-test.php', 'tests/manual/durable-retry-composition-infrastructure-test.php', 'tests/manual/durable-retry-delivery-completion-processor-infrastructure-test.php', 'tests/manual/durable-retry-executor-infrastructure-test.php', 'tests/manual/durable-retry-external-scheduler-infrastructure-test.php', 'tests/manual/durable-retry-initial-authority-producer-infrastructure-test.php', 'tests/manual/durable-retry-initial-transfer-authority-infrastructure-test.php', 'tests/manual/durable-retry-next-generation-infrastructure-test.php', 'tests/manual/durable-retry-processing-nullable-attempt-infrastructure-test.php', 'tests/manual/durable-retry-production-composition-infrastructure-test.php', 'tests/manual/durable-retry-reconciliation-processor-infrastructure-test.php'];
$normalizePaths = static fn (array $paths): array => array_values(array_unique(array_map(static fn (string $path): string => str_replace('\\', '/', $path), $paths)));
$a11AuthorizedExternalPaths = $normalizePaths(array_merge($a11LocalCoexistencePaths, $a11HistoricalMaintenancePaths));
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
$nextGenerationRepositories = array_values(array_filter(
    $repositories,
    static fn (string $path): bool => str_contains(
        file_get_contents($path),
        'function supersedeAndCreateNextGeneration('
    )
));
$assert(
    $nextGenerationRepositories === [$repositoryPath],
    'Only the schedule repository may implement next-generation persistence.'
);
$initialTransferPath = $root
    . '/app/Modules/Orders/Repositories/DurableRetryInitialTransferRepository.php';
$initialTransferRepository = file_get_contents($initialTransferPath);
$assert(
    ! str_contains(
        $initialTransferRepository,
        'supersedeAndCreateNextGeneration'
    ),
    'Initial-transfer repository cannot supersede or create later generations.'
);
$assert(
    str_contains($initialTransferRepository, '$request->generation()')
        && str_contains(
            file_get_contents(
                $root . '/app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialTransferRequest.php'
            ),
            'public const INITIAL_GENERATION = 1'
        ),
    'Initial-transfer persistence is closed to generation one.'
);
$a5Production = implode("\n", array_map(
    'file_get_contents',
    [
        $root . '/app/Modules/Orders/Contracts/DurableRetryInitialAuthorityProducerInterface.php',
        $root . '/app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialAuthorityProductionResult.php',
        $root . '/app/Modules/Orders/Services/DurableRetryInitialAuthorityProducer.php',
    ]
));
$assert(
    ! preg_match('/\\bRepository\\b|\\b(?:SELECT|INSERT|UPDATE|DELETE)\\b/i', $a5Production),
    'A5 contains neither repository authority nor SQL.'
);
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
$restrictedDiff = array_values(array_filter(
    $restrictedDiff,
    static fn (string $path): bool =>
        $path !== 'app/Modules/Orders/Services/DurableRetryExecutor.php'
));
$restrictedDiff = array_values(array_diff($normalizePaths($restrictedDiff), $a11AuthorizedExternalPaths));
$assert(
    $exitCode === 0
        && $restrictedDiff === [],
    'restricted certified paths remain unchanged'
);
$assert(
    str_contains(
        file_get_contents($root . '/app/Core/Config.php'),
        "SCHEMA_VERSION = '0.24.0'"
    ),
    'schema version remains 0.24.0'
);

echo "durable retry next generation infrastructure: {$assertions} assertions\n";
