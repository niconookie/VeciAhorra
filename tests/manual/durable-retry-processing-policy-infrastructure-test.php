<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    'app/Modules/Orders/Contracts/DurableRetryProcessingPolicyInterface.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryNextAttemptDecision.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryProcessingFailure.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryProcessingPolicy.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryProcessingResult.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryReason.php',
];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$source = '';
foreach ($paths as $path) {
    $contents = file_get_contents($root . '/' . $path);
    $assert(is_string($contents), "read {$path}");
    $source .= "\n" . $contents;
}
foreach ([
    '$wpdb',
    'wpdb',
    'SELECT ',
    'INSERT ',
    'UPDATE ',
    'DELETE ',
    'Repository',
    'START TRANSACTION',
    'COMMIT',
    'ROLLBACK',
    'ActionScheduler',
    'as_schedule_',
    'as_get_scheduled_actions',
    'Coordinator',
    'add_action(',
    'add_filter(',
    'wp_schedule_',
    'register_rest_route',
    'wp_enqueue_script',
    'error_log',
    'current_time(',
    'wp_date(',
    'time()',
    'microtime(',
    "DateTimeImmutable('now",
    'Modules\\Payments',
    'Modules\\Fulfillment',
    'Modules\\Delivery',
    'getMessage(',
    'getTrace',
    'print_r(',
    'var_dump(',
] as $forbidden) {
    $assert(! str_contains($source, $forbidden), "forbids {$forbidden}");
}
$assert(substr_count($source, 'readonly') >= 14, 'DTO state is readonly');
$assert(
    str_contains($source, 'private const BACKOFF_BY_ATTEMPT')
        && str_contains($source, '1 => 60')
        && str_contains($source, '4 => 480'),
    'single closed backoff catalog'
);
$assert(! str_contains($source, '**'), 'no unchecked exponentiation');
$assert(
    substr_count($source, 'PROCESSING_ATTEMPTS_EXHAUSTED') >= 2
        && substr_count($source, 'PROCESSING_TERMINAL_FAILURE') >= 2
        && substr_count($source, 'PROCESSING_OUTCOME_UNCERTAIN') >= 2,
    'normative reasons wired'
);
$assert(
    str_contains($source, '$confirmedAttempt !== $persistedAttempt + 1'),
    'stage counter compatibility explicit'
);
$assert(
    str_contains($source, "new DateTimeZone('UTC')")
        && str_contains($source, "format('Y-m-d H:i:s')"),
    'explicit canonical UTC'
);

$policyPaths = [
    'app/Modules/Orders/Contracts/DurableRetryProcessingPolicyInterface.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryNextAttemptDecision.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryProcessingFailure.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryProcessingPolicy.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryProcessingResult.php',
    'app/Modules/Orders/Domain/DurableRetry/DurableRetryReason.php',
    'tests/manual/durable-retry-processing-policy-test.php',
];
$assert(count($policyPaths) === 7, 'exact certified policy path set');
exec(
    'git diff --name-only HEAD -- '
        . implode(' ', array_map('escapeshellarg', $policyPaths)),
    $policyDiff,
    $diffExit
);
$assert($diffExit === 0, 'tracked policy diff inspection succeeds');
$assert($policyDiff === [], 'certified processing policy remains unchanged');

echo "durable retry processing policy infrastructure: {$assertions} assertions\n";
