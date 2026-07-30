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
$assert($exit === 0 && $lines($trackedOutput) === [], 'Tracked files must be intact.');
[$exit, $untrackedOutput] = $git('ls-files --others --exclude-standard');
$assert($exit === 0, 'Untracked inventory must be readable.');
$untracked = $lines($untrackedOutput);
$actualA4 = array_values(array_intersect($untracked, $allowed));
sort($actualA4);
$expectedA4 = $allowed;
sort($expectedA4);
$assert($actualA4 === $expectedA4, 'Exactly the six allowlisted A4 files must be untracked.');
$a4Candidates = array_values(array_filter(
    $untracked,
    static fn (string $path): bool =>
        str_contains($path, 'InitialTransfer')
        || str_contains($path, 'initial-transfer-authority')
));
$assert(count($a4Candidates) === 6, 'A seventh A4 file must not exist.');
[$exit, $whitespaceOutput] = $git('diff --check');
$assert($exit === 0 && $lines($whitespaceOutput) === [], 'Tracked whitespace guard must pass.');
[$exit, $cachedWhitespaceOutput] = $git('diff --cached --check');
$assert($exit === 0 && $lines($cachedWhitespaceOutput) === [], 'Cached whitespace guard must pass.');
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
    array $files,
    bool $stagingEmpty,
    bool $trackedEmpty,
    bool $protectedIntact,
    int $artifactCount
): bool =>
    $files === $expectedA4
    && $stagingEmpty
    && $trackedEmpty
    && $protectedIntact
    && $artifactCount === 504;
$assert(! $guard([...$expectedA4, 'tests/manual/a4-seventh.php'], true, true, true, 504), 'Guard rejects a seventh file.');
$assert(! $guard($expectedA4, false, true, true, 504), 'Guard rejects non-empty staging.');
$assert(! $guard($expectedA4, true, false, true, 504), 'Guard rejects tracked modifications.');
$assert(! $guard($expectedA4, true, true, false, 504), 'Guard rejects protected modifications.');
$assert(! $guard($expectedA4, true, true, true, 503), 'Guard rejects incorrect artifact count.');

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
