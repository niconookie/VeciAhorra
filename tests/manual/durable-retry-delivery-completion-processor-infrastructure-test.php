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
$processorPath = 'app/Modules/Orders/Services/DurableRetryDeliveryCompletionProcessor.php';
$attemptPath = 'app/Modules/Delivery/Completion/Contracts/DeliveryCompletionAttemptProcessorInterface.php';
$readPath = 'app/Modules/Delivery/Completion/Contracts/DeliveryCompletionReadAuthorityInterface.php';
$paths = [$processorPath, $attemptPath, $readPath];
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
$assert(str_contains($processor, 'implements DurableRetryStageProcessorInterface'), 'implements stage processor');
$assert(str_contains($processor, 'return DurableRetryStage::DELIVERY_COMPLETION;'), 'closed delivery stage');
$assert(substr_count($processor, 'DeliveryCompletionAttemptProcessorInterface $attemptProcessor') === 1, 'exact attempt dependency');
$assert(substr_count($processor, 'DeliveryCompletionReadAuthorityInterface $readAuthority') === 1, 'exact read dependency');
$assert(substr_count($processor, '$this->attemptProcessor->process(') === 1, 'one attempt call site');
$assert(substr_count($processor, '$this->readAuthority->findByBusinessCompletion(') === 1, 'one read call site');
$assert(str_contains($processor, "'worker_' . bin2hex(random_bytes(16))"), 'closed worker identity');
$assert(str_contains($processor, '$context->subjectId()'), 'uses business completion subject');
$assert(str_contains($processor, '$context->completionId() === null'), 'nullable delivery completion');
$assert(! str_contains($processor, 'completionId() === $context->subjectId()'), 'identities remain distinct');
$assert(substr_count($processor, 'catch (PersistenceException)') === 2, 'only recognized infrastructure mapped');
$assert(! str_contains($processor, 'catch (Throwable'), 'unknown programming errors not swallowed');

foreach ([
    '$wpdb', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'error_log',
    'as_schedule_', 'add_action', 'add_filter', 'do_action', 'wp_schedule_',
    'register_rest_route', 'sleep(', 'usleep(', 'actionscheduler_',
    'current_time(', 'wp_date(', 'foreach (', 'while (', 'for (',
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
        && str_contains($source, 'DurableRetryStage::DELIVERY_COMPLETION')
    ) {
        $implementations[] = basename($path);
    }
}
$assert(
    $implementations === ['DurableRetryDeliveryCompletionProcessor.php'],
    'single delivery completion implementation'
);
$functional = file_get_contents(
    $root . '/app/Modules/Delivery/Completion/Service/DeliveryCompletionProcessor.php'
);
$repository = file_get_contents(
    $root . '/app/Modules/Delivery/Completion/Repository/DeliveryCompletionRepository.php'
);
$assert(
    is_string($functional)
        && str_contains($functional, 'implements DeliveryCompletionAttemptProcessorInterface'),
    'existing functional processor implements attempt port'
);
$assert(
    is_string($repository)
        && str_contains($repository, 'implements DeliveryCompletionReadAuthorityInterface'),
    'existing repository implements read port'
);

$restricted = [
    'app/Core/Config.php',
    'app/Database',
    'app/Modules/Orders/Repositories',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryProcessingPolicy.php',
    'app/Modules/Orders/Infrastructure',
    'app/Modules/Fulfillment/Orchestration',
    'app/Modules/Fulfillment/Completion',
    'app/Modules/Payments',
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

echo "durable retry delivery completion processor infrastructure: {$assertions} assertions\n";
