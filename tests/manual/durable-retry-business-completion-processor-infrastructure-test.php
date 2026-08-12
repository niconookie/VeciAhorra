<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$a11LocalCoexistencePaths = ['app/Core/Application.php', 'app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php', 'app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php', 'tests/manual/durable-completion-orchestration-test.php', 'tests/manual/support/durable-retry-a11-coordinator.php', 'tests/manual/support/durable-retry-a11-runtime-capture-contract.php', 'tests/manual/durable-retry-a11-runtime-capture-test.php', 'tests/manual/durable-retry-a11-runtime-capture-infrastructure-test.php'];
$a11HistoricalMaintenancePaths = ['tests/manual/durable-retry-action-callback-infrastructure-test.php', 'tests/manual/durable-retry-action-hook-registrar-infrastructure-test.php', 'tests/manual/durable-retry-business-completion-processor-infrastructure-test.php', 'tests/manual/durable-retry-composition-infrastructure-test.php', 'tests/manual/durable-retry-delivery-completion-processor-infrastructure-test.php', 'tests/manual/durable-retry-executor-infrastructure-test.php', 'tests/manual/durable-retry-external-scheduler-infrastructure-test.php', 'tests/manual/durable-retry-initial-authority-producer-infrastructure-test.php', 'tests/manual/durable-retry-initial-transfer-authority-infrastructure-test.php', 'tests/manual/durable-retry-next-generation-infrastructure-test.php', 'tests/manual/durable-retry-processing-nullable-attempt-infrastructure-test.php', 'tests/manual/durable-retry-production-composition-infrastructure-test.php', 'tests/manual/durable-retry-reconciliation-processor-infrastructure-test.php'];
$normalizePaths = static fn (array $paths): array => array_values(array_unique(array_map(static fn (string $path): string => str_replace('\\', '/', $path), $paths)));
$a11AuthorizedExternalPaths = $normalizePaths(array_merge($a11LocalCoexistencePaths, $a11HistoricalMaintenancePaths));
$processorPath = 'app/Modules/Orders/Services/DurableRetryBusinessCompletionProcessor.php';
$attemptInterface = 'app/Modules/Payments/BusinessCompletion/Contracts/BusinessCompletionAttemptProcessorInterface.php';
$readInterface = 'app/Modules/Payments/BusinessCompletion/Contracts/BusinessCompletionReadAuthorityInterface.php';
$paths = [$processorPath, $attemptInterface, $readInterface];
$sources = [];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) { throw new RuntimeException($message); }
};
foreach ($paths as $path) {
    $source = file_get_contents($root . '/' . $path);
    $assert(is_string($source), "{$path} exists");
    $sources[$path] = $source;
}
$processor = $sources[$processorPath];
$combined = implode("\n", $sources);
$assert(str_contains($processor, 'implements DurableRetryStageProcessorInterface'), 'implements durable processor');
$assert(str_contains($processor, 'return DurableRetryStage::BUSINESS_COMPLETION;'), 'closed business stage');
$assert(substr_count($processor, 'BusinessCompletionAttemptProcessorInterface $attemptProcessor') === 1, 'exact attempt dependency');
$assert(substr_count($processor, 'BusinessCompletionReadAuthorityInterface $readAuthority') === 1, 'exact read dependency');
$assert(substr_count($processor, '$this->attemptProcessor->process(') === 1, 'one attempt call site');
$assert(substr_count($processor, '$this->readAuthority->findByReconciliation(') === 1, 'one read call site');
$assert(! str_contains($processor, 'completionId() === $context->subjectId()'), 'does not conflate identities');
$assert(str_contains($processor, '$context->subjectId()'), 'uses durable subject');
$assert(str_contains($processor, '$context->completionId() === null'), 'nullable completion supported');
$assert(str_contains($processor, "'unsupported_origin'"), 'permanent allowlist closed');
$assert(str_contains($processor, "'reconciliation_changed'"), 'manual review allowlist closed');
$assert(str_contains($processor, "'unexpected_failure'"), 'retryable allowlist closed');

foreach ([
    '$wpdb', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'error_log',
    'as_schedule_', 'add_action', 'do_action', 'wp_', 'sleep(',
    'usleep(', 'while (', 'for (', 'foreach (',
] as $forbidden) {
    $assert(! str_contains($combined, $forbidden), "forbids {$forbidden}");
}
$tokens = token_get_all($processor);
$loopTokens = [T_FOR, T_FOREACH, T_WHILE, T_DO];
$assert(
    array_filter($tokens, static fn (mixed $token): bool =>
        is_array($token) && in_array($token[0], $loopTokens, true)
    ) === [],
    'zero loop tokens'
);

$services = glob($root . '/app/Modules/Orders/Services/*.php') ?: [];
$implementations = [];
foreach ($services as $path) {
    $source = file_get_contents($path);
    if (is_string($source)
        && str_contains($source, 'implements DurableRetryStageProcessorInterface')
        && str_contains($source, 'DurableRetryStage::BUSINESS_COMPLETION')
    ) {
        $implementations[] = basename($path);
    }
}
$assert(
    $implementations === ['DurableRetryBusinessCompletionProcessor.php'],
    'single business completion implementation'
);
$businessProcessor = file_get_contents(
    $root . '/app/Modules/Payments/BusinessCompletion/Service/BusinessCompletionProcessor.php'
);
$businessRepository = file_get_contents(
    $root . '/app/Modules/Payments/BusinessCompletion/Repository/BusinessCompletionRepository.php'
);
$assert(
    is_string($businessProcessor)
        && str_contains($businessProcessor, 'implements BusinessCompletionAttemptProcessorInterface'),
    'existing processor implements attempt contract'
);
$assert(
    is_string($businessRepository)
        && str_contains($businessRepository, 'implements BusinessCompletionReadAuthorityInterface'),
    'existing repository implements read contract'
);

$restricted = [
    'app/Core/Config.php',
    'app/Database',
    'app/Modules/Orders/Repositories',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryProcessingPolicy.php',
    'app/Modules/Orders/Infrastructure',
    'app/Modules/Fulfillment/Orchestration',
    'app/Modules/Delivery',
    'app/Modules/Fulfillment/Completion',
    'docs',
    'artifacts',
];
exec(
    'git diff --name-only HEAD -- '
        . implode(' ', array_map('escapeshellarg', $restricted)),
    $restrictedDiff,
    $restrictedExit
);
$restrictedDiff = array_values(array_diff($normalizePaths($restrictedDiff), $a11AuthorizedExternalPaths));
$assert(
    $restrictedExit === 0
        && $restrictedDiff === [],
    'restricted certified paths remain unchanged'
);
$assert(
    str_contains(
        file_get_contents($root . '/app/Core/Config.php'),
        "SCHEMA_VERSION = '0.24.0'"
    ),
    'schema remains 0.24.0'
);

echo "durable retry business completion processor infrastructure: {$assertions} assertions\n";
