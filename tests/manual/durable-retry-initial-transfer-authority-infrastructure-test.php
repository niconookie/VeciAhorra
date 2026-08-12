<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialTransferAuthorityInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialTransferRepositoryInterface;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryInitialTransferRepository;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialTransferAuthority;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$root = dirname(__DIR__, 2);
$a11LocalCoexistencePaths = ['app/Core/Application.php', 'app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php', 'app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php', 'tests/manual/durable-completion-orchestration-test.php', 'tests/manual/support/durable-retry-a11-coordinator.php', 'tests/manual/support/durable-retry-a11-runtime-capture-contract.php', 'tests/manual/durable-retry-a11-runtime-capture-test.php', 'tests/manual/durable-retry-a11-runtime-capture-infrastructure-test.php'];
$a11HistoricalMaintenancePaths = ['tests/manual/durable-retry-action-callback-infrastructure-test.php', 'tests/manual/durable-retry-action-hook-registrar-infrastructure-test.php', 'tests/manual/durable-retry-business-completion-processor-infrastructure-test.php', 'tests/manual/durable-retry-composition-infrastructure-test.php', 'tests/manual/durable-retry-delivery-completion-processor-infrastructure-test.php', 'tests/manual/durable-retry-executor-infrastructure-test.php', 'tests/manual/durable-retry-external-scheduler-infrastructure-test.php', 'tests/manual/durable-retry-initial-authority-producer-infrastructure-test.php', 'tests/manual/durable-retry-initial-transfer-authority-infrastructure-test.php', 'tests/manual/durable-retry-next-generation-infrastructure-test.php', 'tests/manual/durable-retry-processing-nullable-attempt-infrastructure-test.php', 'tests/manual/durable-retry-production-composition-infrastructure-test.php', 'tests/manual/durable-retry-reconciliation-processor-infrastructure-test.php'];
$normalizePaths = static fn (array $paths): array => array_values(array_unique(array_map(static fn (string $path): string => str_replace('\\', '/', $path), $paths)));
$a11AuthorizedExternalPaths = $normalizePaths(array_merge($a11LocalCoexistencePaths, $a11HistoricalMaintenancePaths));
$allowed = [
    'app/Modules/Orders/Contracts/DurableRetryInitialTransferRepositoryInterface.php',
    'app/Modules/Orders/Repositories/DurableRetryInitialTransferRepository.php',
    'app/Modules/Orders/Services/DurableRetryInitialTransferAuthority.php',
    'tests/manual/durable-retry-initial-transfer-authority-test.php',
    'tests/manual/durable-retry-initial-transfer-authority-mysql-test.php',
    'tests/manual/durable-retry-initial-transfer-authority-infrastructure-test.php',
];
foreach ($allowed as $file) {
    $assert(is_file($root . '/' . $file), "Allowlisted file missing: {$file}");
}
$git = static function (string $arguments) use ($root): array {
    $output = [];
    $exit = 0;
    exec('git -C ' . escapeshellarg($root) . ' ' . $arguments . ' 2>&1', $output, $exit);

    return [$exit, $output];
};
$lines = static fn (array $output): array => array_values(array_filter(
    array_map('trim', $output),
    static fn (string $line): bool => $line !== ''
));
[$exit, $stagedOutput] = $git('diff --cached --name-only');
$assert($exit === 0 && $lines($stagedOutput) === [], 'Staging must be empty.');
[$exit, $trackedOutput] = $git('diff --name-only');
$maintenanceAllowlist = [
    'tests/manual/durable-retry-activation-configuration-source-infrastructure-test.php',
    'tests/manual/durable-retry-activation-flag-policy-infrastructure-test.php',
    'tests/manual/durable-retry-initial-transfer-authority-infrastructure-test.php',
    'tests/manual/durable-retry-legacy-authority-infrastructure-test.php',
    'tests/manual/durable-retry-next-generation-infrastructure-test.php',
    'tests/manual/durable-retry-schedule-infrastructure-test.php',
    'tests/manual/durable-retry-initial-authority-producer-infrastructure-test.php',
];
$trackedChanges = array_values(array_filter(
    $lines($trackedOutput),
    static fn (string $line): bool => ! str_starts_with($line, 'warning:')
));
$trackedChanges = $normalizePaths($trackedChanges);
$assert(
    $exit === 0
        && array_diff($trackedChanges, array_merge($maintenanceAllowlist, $a11AuthorizedExternalPaths)) === [],
    'Tracked changes must remain inside the infrastructure maintenance allowlist.'
);
foreach ($allowed as $file) {
    [$exit] = $git(
        'ls-files --error-unmatch -- ' . escapeshellarg($file)
    );
    $assert($exit === 0, "A4 path must remain versioned: {$file}");
}
[$exit, $whitespaceOutput] = $git('diff --check');
$whitespaceLines = array_values(array_filter(
    $lines($whitespaceOutput),
    static fn (string $line): bool => ! str_starts_with($line, 'warning:')
));
$assert($exit === 0 && $whitespaceLines === [], 'Tracked whitespace guard must pass.');
[$exit, $cachedWhitespaceOutput] = $git('diff --cached --check');
$cachedWhitespaceLines = array_values(array_filter(
    $lines($cachedWhitespaceOutput),
    static fn (string $line): bool => ! str_starts_with($line, 'warning:')
));
$assert($exit === 0 && $cachedWhitespaceLines === [], 'Cached whitespace guard must pass.');
$artifactEntries = iterator_to_array(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $root . '/artifacts',
            FilesystemIterator::SKIP_DOTS
        )
    ));
$assert(
    count(array_filter(
        $artifactEntries,
        static fn (SplFileInfo $entry): bool => $entry->isFile()
    )) === 504,
    'Artifacts inventory must contain exactly 504 files.'
);
$guard = static fn (
    array $changedFiles,
    bool $stagingEmpty,
    bool $protectedIntact,
    int $artifactCount
): bool =>
    array_diff($changedFiles, $maintenanceAllowlist) === []
    && $stagingEmpty
    && $protectedIntact
    && $artifactCount === 504;
$assert(! $guard(['tests/manual/unexpected.php'], true, true, 504), 'Guard rejects an unrelated tracked file.');
$assert(! $guard([], false, true, 504), 'Guard rejects non-empty staging.');
$assert(! $guard([], true, false, 504), 'Guard rejects protected modifications.');
$assert(! $guard([], true, true, 503), 'Guard rejects incorrect artifact count.');

$repository = new ReflectionClass(DurableRetryInitialTransferRepository::class);
$service = new ReflectionClass(DurableRetryInitialTransferAuthority::class);
$assert($repository->isFinal(), 'Repository must be final.');
$assert($service->isFinal(), 'Service must be final.');
$assert($repository->implementsInterface(DurableRetryInitialTransferRepositoryInterface::class), 'Repository contract exact.');
$assert($service->implementsInterface(DurableRetryInitialTransferAuthorityInterface::class), 'A1 authority contract exact.');
$assert(count($repository->getConstructor()->getParameters()) === 1, 'Repository constructor arity exact.');
$assert((string) $repository->getConstructor()->getParameters()[0]->getType() === 'wpdb', 'Repository dependency exact.');
$assert(count($service->getConstructor()->getParameters()) === 1, 'Service constructor arity exact.');
$assert((string) $service->getConstructor()->getParameters()[0]->getType() === DurableRetryInitialTransferRepositoryInterface::class, 'Service dependency exact.');
$assert(count($repository->getMethods(ReflectionMethod::IS_PUBLIC)) === 2, 'Repository has constructor and one public operation.');
$transfer = $repository->getMethod('transferReconciliation');
$assert(count($transfer->getParameters()) === 1, 'Repository operation arity exact.');
$assert(
    (string) $transfer->getReturnType()
        === 'VeciAhorra\\Modules\\Orders\\Domain\\DurableRetry\\DurableRetryInitialTransferResult',
    'Repository return contract exact.'
);

$repoSource = file_get_contents($repository->getFileName());
$serviceSource = file_get_contents($service->getFileName());
$allSource = $repoSource . $serviceSource;
$assert(str_contains($repoSource, 'START TRANSACTION'), 'Transaction start present.');
$functionalLock = strpos($repoSource, 'payment_reconciliations');
$durableLock = strpos($repoSource, 'durable_retry_schedules');
$assert($functionalLock !== false && $durableLock !== false, 'Both authorities named.');
$assert(str_contains($repoSource, 'FOR UPDATE'), 'Row locks present.');
$assert(str_contains($repoSource, 'Config::TABLE_PREFIX'), 'Authoritative table prefix used.');
$assert(! str_contains($repoSource, 'veciahorra_durable_retry_schedules'), 'Forbidden physical name absent.');
$assert(substr_count($repoSource, 'INSERT INTO') === 1, 'One insert site.');
$assert(
    ! str_contains($repoSource, "'UPDATE ")
        && ! str_contains($repoSource, "'DELETE "),
    'No update/delete SQL.'
);
$assert(substr_count($repoSource, 'prepare(') >= 4, 'Queries are prepared.');
foreach ([
    'PRE_TRANSACTION',
    'TRANSACTION_STARTED',
    'READS_AND_LOCKS',
    'PRE_WRITE',
    'WRITE_ATTEMPTED',
    'COMMIT_ATTEMPTED',
    'COMMIT_UNCERTAIN',
    'CLOSE_CONFIRMED',
] as $phase) {
    $assert(str_contains($repoSource, "'{$phase}'"), "Private phase present: {$phase}");
}
$assert(str_contains($repoSource, 'new wpdb('), 'Commit uncertainty uses an independent wpdb connection.');

foreach ([
    'DurableRetryActivationPolicy',
    'DurableRetryActivationConfiguration',
    'DurableRetryLegacyAuthorityRepository',
    'get_option(',
    'add_action(',
    'add_filter(',
    'do_action(',
    'as_schedule_',
    'as_enqueue_',
    'sleep(',
    'usleep(',
    'Application',
] as $forbidden) {
    $assert(! str_contains($allSource, $forbidden), "Forbidden dependency absent: {$forbidden}");
}
$assert(! preg_match('/\b(?:while|do)\s*\(/', $repoSource), 'Repository has no retry loops.');
$assert(
    ! str_contains($repoSource, 'last_error .')
        && ! str_contains($repoSource, '. last_error'),
    'Results do not expose raw database errors.'
);

foreach ([
    'app/Core/Config.php',
    'app/Core/Application.php',
    'app/Database/Schemas/DurableRetryScheduleSchema.php',
    'app/Database/Schemas/PaymentReconciliationSchema.php',
    'app/Modules/Orders/Repositories/DurableRetryLegacyAuthorityRepository.php',
    'app/Modules/Fulfillment/Orchestration/DurableCompletionScheduler.php',
    'app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php',
] as $protected) {
    $assert(is_file($root . '/' . $protected), "Protected file remains present: {$protected}");
}

echo "OK durable retry initial transfer authority infrastructure ({$assertions} assertions)\n";
