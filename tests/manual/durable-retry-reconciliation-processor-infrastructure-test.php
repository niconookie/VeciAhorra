<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;

$root = dirname(__DIR__, 2);
require_once $root . '/app/Core/Config.php';
require_once $root . '/tests/manual/support/durable-retry-head-delta-guard.php';
$a11LocalCoexistencePaths = ['app/Core/Application.php', 'app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php', 'app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php', 'tests/manual/durable-completion-orchestration-test.php', 'tests/manual/support/durable-retry-a11-coordinator.php', 'tests/manual/support/durable-retry-a11-runtime-capture-contract.php', 'tests/manual/durable-retry-a11-runtime-capture-test.php', 'tests/manual/durable-retry-a11-runtime-capture-infrastructure-test.php'];
$a11HistoricalMaintenancePaths = ['tests/manual/durable-retry-action-callback-infrastructure-test.php', 'tests/manual/durable-retry-action-hook-registrar-infrastructure-test.php', 'tests/manual/durable-retry-business-completion-processor-infrastructure-test.php', 'tests/manual/durable-retry-composition-infrastructure-test.php', 'tests/manual/durable-retry-delivery-completion-processor-infrastructure-test.php', 'tests/manual/durable-retry-executor-infrastructure-test.php', 'tests/manual/durable-retry-external-scheduler-infrastructure-test.php', 'tests/manual/durable-retry-initial-authority-producer-infrastructure-test.php', 'tests/manual/durable-retry-initial-transfer-authority-infrastructure-test.php', 'tests/manual/durable-retry-next-generation-infrastructure-test.php', 'tests/manual/durable-retry-processing-nullable-attempt-infrastructure-test.php', 'tests/manual/durable-retry-production-composition-infrastructure-test.php', 'tests/manual/durable-retry-reconciliation-processor-infrastructure-test.php'];
$normalizePaths = static fn (array $paths): array => array_values(array_unique(array_map(static fn (string $path): string => str_replace('\\', '/', $path), $paths)));
$a11AuthorizedExternalPaths = $normalizePaths(array_merge($a11LocalCoexistencePaths, $a11HistoricalMaintenancePaths));
$processorPath = 'app/Modules/Orders/Services/DurableRetryReconciliationProcessor.php';
$interfacePaths = [
    'app/Modules/Payments/Reconciliation/Contracts/PaymentReconciliationAttemptProcessorInterface.php',
    'app/Modules/Payments/Reconciliation/Contracts/PaymentReconciliationLeaseAuthorityInterface.php',
    'app/Modules/Payments/Reconciliation/Contracts/PaymentReconciliationReadAuthorityInterface.php',
];
$productionPaths = array_merge([$processorPath], $interfacePaths);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$sources = [];
foreach ($productionPaths as $path) {
    $source = file_get_contents($root . '/' . $path);
    $assert(is_string($source), "read {$path}");
    $sources[$path] = $source;
}
$processor = $sources[$processorPath];
$newSource = implode("\n", $sources);

$assert(
    str_contains($processor, 'implements DurableRetryStageProcessorInterface'),
    'implements durable stage processor'
);
$assert(
    str_contains($processor, 'return DurableRetryStage::RECONCILIATION;'),
    'uses closed reconciliation stage'
);
$assert(
    substr_count($processor, '$this->claims->acquireLease(') === 1,
    'one claim call site'
);
$assert(
    substr_count($processor, '$this->attempts->process($lease)') === 1,
    'one functional attempt call site'
);
$assert(
    substr_count($processor, '$this->reconciliations->find(') === 1,
    'one authoritative reread call site'
);
$assert(
    str_contains($processor, '$lease->confirmedAttemptNumber()')
        && str_contains($processor, '$authority->attemptCount()'),
    'counter sourced from functional authorities'
);
$assert(
    substr_count($processor, '$context->expectedAttemptNumber()') === 3
        && ! str_contains(
            $processor,
            'outcomeUncertain($context->expectedAttemptNumber()'
        ),
    'expected attempt used only for validation'
);
$assert(
    str_contains($processor, 'DurableRetryProcessingResult::outcomeUncertain()'),
    'nullable uncertainty explicit'
);
$assert(
    str_contains($processor, "=== 'attempts_exhausted'"),
    'attempt exhaustion reason closed'
);
$assert(
    str_contains($processor, 'STATUS_MANUAL_REVIEW')
        && str_contains($processor, 'COMPLETION_REJECTED'),
    'manual review matrix uses outcome evidence'
);

foreach ([
    'DurableCompletionWorkers',
    'DurableCompletionScheduler',
    'DurableRetryExecutor',
    'DurableRetryScheduleRepository',
    'DurableRetryProcessingPolicy',
    'DurableRetryExternalScheduleCoordinator',
    'DurableRetryExternalScheduler',
    'ActionScheduler',
    'as_schedule_single_action',
    'as_enqueue_async_action',
    'add_action(',
    'add_filter(',
    'wp_schedule_',
    'register_rest_route',
    '$wpdb',
    'SELECT ',
    'INSERT ',
    'UPDATE ',
    'DELETE ',
    'START TRANSACTION',
    'COMMIT',
    'ROLLBACK',
    'error_log(',
    'trigger_error(',
    'getMessage(',
    'getTrace',
    'Modules\\Delivery',
    'Modules\\Fulfillment',
    'BusinessCompletion',
] as $forbidden) {
    $assert(! str_contains($newSource, $forbidden), "forbids {$forbidden}");
}

$services = glob($root . '/app/Modules/Orders/Services/*.php');
$implementations = [];
foreach ($services as $path) {
    $source = file_get_contents($path);
    if (is_string($source)
        && str_contains($source, 'implements DurableRetryStageProcessorInterface')
        && str_contains($source, 'DurableRetryStage::RECONCILIATION')
    ) {
        $implementations[] = basename($path);
    }
}
$assert(
    $implementations === ['DurableRetryReconciliationProcessor.php'],
    'single reconciliation stage implementation'
);
$assert(
    is_file($root . '/app/Modules/Orders/Services/DurableRetryFulfillmentProcessor.php')
        && ! is_file($root . '/app/Modules/Orders/Services/DurableRetryFulfillmentCompletionProcessor.php'),
    'fulfillment stage processor uses the certified name'
);

exec(
    'git diff --name-only HEAD -- '
        . escapeshellarg('app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php'),
    $workerDiff,
    $workerExit
);
$workerDiff = array_values(array_diff($normalizePaths($workerDiff), $a11AuthorizedExternalPaths));
$assert($workerExit === 0 && $workerDiff === [], 'legacy worker unchanged');

exec(
    'git diff --unified=0 HEAD -- '
        . escapeshellarg('app/Modules/Payments/Reconciliation/Repository/PaymentReconciliationClaimRepository.php')
        . ' '
        . escapeshellarg('app/Modules/Payments/Reconciliation/Repository/PaymentReconciliationRepository.php'),
    $repositoryDiff,
    $repositoryExit
);
$addedRepositoryLines = array_filter(
    $repositoryDiff,
    static fn (string $line): bool =>
        str_starts_with($line, '+')
        && ! str_starts_with($line, '+++')
);
$addedRepositorySource = implode("\n", $addedRepositoryLines);
$assert($repositoryExit === 0, 'repository diff inspection succeeds');
$assert(
    ! preg_match('/\\b(SELECT|INSERT|UPDATE|DELETE)\\b/', $addedRepositorySource),
    'zero new repository SQL'
);

$restricted = [
    'app/Core/Config.php',
    'app/Database',
    'app/Modules/Orders/Repositories',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryProcessingPolicy.php',
    'app/Modules/Orders/Infrastructure',
    'app/Modules/Fulfillment/Orchestration/DurableCompletionScheduler.php',
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
$restrictedDiff = DurableRetryHeadDeltaGuard::unauthorizedPaths($root, $restrictedDiff);
$assert(
    $restrictedExit === 0
        && $restrictedDiff === [],
    'restricted certified paths remain unchanged'
);
$assert(
    is_string(Config::SCHEMA_VERSION)
        && version_compare(Config::SCHEMA_VERSION, '0.24.0', '>='),
    'schema remains compatible with 0.24.0'
);

echo "durable retry reconciliation processor infrastructure: {$assertions} assertions\n";
