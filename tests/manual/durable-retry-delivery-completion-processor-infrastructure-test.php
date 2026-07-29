<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
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
    'app/Modules/Orders/Services/DurableRetryExecutor.php',
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
$assert(
    $restrictedExit === 0
        && $restrictedDiff === [],
    'restricted certified paths remain unchanged'
);
$assert(
    str_contains(file_get_contents($root . '/app/Core/Config.php'), "SCHEMA_VERSION = '0.24.0'"),
    'schema remains 0.24.0'
);

echo "durable retry delivery completion processor infrastructure: {$assertions} assertions\n";
