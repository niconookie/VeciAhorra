<?php

declare(strict_types=1);

use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationClaimRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

[
    $script,
    $action,
    $reconciliationId,
    $owner,
    $duration,
    $leaseVersion,
    $expectedStatus,
    $nextStatus,
    $barrier,
    $ready,
] = array_pad($argv, 10, null);

if (
    ! in_array($action, ['acquire', 'cas'], true)
    || ! ctype_digit((string) $reconciliationId)
    || ! ctype_digit((string) $duration)
    || ! ctype_digit((string) $leaseVersion)
    || ! is_string($barrier)
    || ! is_string($ready)
) {
    fwrite(STDERR, "Argumentos de worker invalidos.\n");
    exit(2);
}

file_put_contents($ready, 'ready', LOCK_EX);
$deadline = microtime(true) + 10.0;

while (! is_file($barrier)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Timeout esperando barrera.\n");
        exit(3);
    }

    usleep(10000);
    clearstatcache(true, $barrier);
}

try {
    $repository = new PaymentReconciliationClaimRepository();

    if ($action === 'acquire') {
        $result = $repository->acquireLease(
            (int) $reconciliationId,
            (string) $owner,
            (int) $duration
        );
    } else {
        $result = $repository->compareAndSetStatus(
            (int) $reconciliationId,
            (string) $owner,
            (int) $leaseVersion,
            (string) $expectedStatus,
            (string) $nextStatus
        );
    }

    echo json_encode([
        'action' => $action,
        'status' => $result->status(),
        'worker_owner' => $owner,
        'next_status' => $nextStatus,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . "\n");
    exit(4);
}
