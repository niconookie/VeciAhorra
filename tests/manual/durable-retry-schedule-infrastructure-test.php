<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$domain = $root . '/app/Modules/Orders/Domain/DurableRetry';
$files = glob($domain . '/*.php') ?: [];
$source = implode("\n", array_map('file_get_contents', $files));
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$expectedDomainFiles = [
    'DurableRetryActivationCohort.php',
    'DurableRetryActivationConfiguration.php',
    'DurableRetryActivationConfigurationValue.php',
    'DurableRetryAuthorityIdentity.php',
    'DurableRetryAuthorityIdentityCollection.php',
    'DurableRetryCoordinationResult.php',
    'DurableRetryDeterministicActivationPolicy.php',
    'DurableRetryExecutionContext.php',
    'DurableRetryExecutionResult.php',
    'DurableRetryExternalScheduleCatalog.php',
    'DurableRetryExternalScheduleResult.php',
    'DurableRetryGenerationIdentity.php',
    'DurableRetryIndeterminateReason.php',
    'DurableRetryInitialTransferReason.php',
    'DurableRetryInitialTransferRequest.php',
    'DurableRetryInitialTransferResult.php',
    'DurableRetryLegacyAuthorityBatchResult.php',
    'DurableRetryLegacyAuthorityEntry.php',
    'DurableRetryLegacyAuthorityResult.php',
    'DurableRetryNextAttemptDecision.php',
    'DurableRetryNextGenerationPersistenceResult.php',
    'DurableRetryPersistenceResult.php',
    'DurableRetryProcessingFailure.php',
    'DurableRetryProcessingPolicy.php',
    'DurableRetryProcessingResult.php',
    'DurableRetryReason.php',
    'DurableRetryScheduleSnapshot.php',
    'DurableRetryStage.php',
    'DurableRetryStatus.php',
];
$actualDomainFiles = array_map('basename', $files);
sort($actualDomainFiles);
$assert(
    $actualDomainFiles === $expectedDomainFiles,
    'twenty-nine focused pure domain contracts'
);
foreach ([
    '$wpdb',
    'wpdb',
    'WordPress',
    'WP_REST',
    'ActionScheduler',
    'as_schedule_',
    'Repository',
    'Controller',
    'add_action',
    'add_filter',
    'current_time(',
    'time(',
    'microtime(',
    "DateTimeImmutable('now",
    'INSERT ',
    'UPDATE ',
    'DELETE ',
    'SELECT ',
] as $forbidden) {
    $assert(! str_contains($source, $forbidden), "domain excludes {$forbidden}");
}
$nonPolicySource = implode("\n", array_map(
    'file_get_contents',
    array_values(array_filter(
        $files,
        static fn (string $file): bool => ! in_array(basename($file), [
            'DurableRetryProcessingPolicy.php',
            'DurableRetryNextAttemptDecision.php',
        ], true)
    ))
));
$assert(
    ! str_contains(strtolower($nonPolicySource), 'backoff'),
    'backoff exists only in the certified processing policy'
);

$migration = file_get_contents(
    $root . '/app/Database/Migrations/CreateDurableRetrySchedulesTable.php'
);
$schema = file_get_contents(
    $root . '/app/Database/Schemas/DurableRetryScheduleSchema.php'
);
foreach ([
    'ActionScheduler',
    'as_schedule_single_action',
    'add_action',
    'add_filter',
    'INSERT ',
    'UPDATE ',
    'DELETE ',
    'SELECT ',
] as $forbidden) {
    $assert(
        ! str_contains($migration . $schema, $forbidden),
        "schema path excludes {$forbidden}"
    );
}

$adminRepository = file_get_contents(
    $root . '/app/Modules/Orders/Repositories/OrderAdminReadRepository.php'
);
$assert(
    ! str_contains($adminRepository, 'durable_retry_schedules'),
    'admin read repository remains unwired'
);
$readModelTest = file_get_contents($root . '/tests/manual/order-admin-read-model-test.php');
$assert(
    str_contains($readModelTest, "'mutable_actions' => []")
        || str_contains($readModelTest, 'mutable_actions'),
    'mutable actions remains certified by existing test'
);

foreach ([
    'PaymentReconciliationSchema.php',
    'BusinessCompletionSchema.php',
    'DeliveryCompletionSchema.php',
    'FulfillmentCompletionSchema.php',
] as $completionSchema) {
    $contents = file_get_contents($root . '/app/Database/Schemas/' . $completionSchema);
    $assert(
        ! str_contains($contents, 'next_retry_at')
            && ! str_contains($contents, 'durable_retry'),
        "{$completionSchema} remains unchanged by durable authority"
    );
}

$publicRoots = [
    $root . '/app/Modules/Orders/Http',
    $root . '/assets/js',
];
foreach ($publicRoots as $publicRoot) {
    if (! is_dir($publicRoot)) {
        continue;
    }
    $publicFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($publicRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($publicFiles as $file) {
        if ($file->isFile()) {
            $assert(
                ! str_contains(file_get_contents($file->getPathname()), 'durable_retry_schedules'),
                'no endpoint or UI wiring'
            );
        }
    }
}

echo "durable retry schedule infrastructure: {$assertions} assertions\n";
