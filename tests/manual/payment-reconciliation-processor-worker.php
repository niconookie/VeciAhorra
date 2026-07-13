<?php

declare(strict_types=1);

use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentReconciliationTechnicalEvaluatorInterface;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationReferences;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\TechnicalReconciliationResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationClaimRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Service\PaymentReconciliationProcessor;
use VeciAhorra\Modules\Payments\Reconciliation\Service\PaymentReconciliationTechnicalEvaluator;

require_once dirname(__DIR__, 5) . '/wp-load.php';

final class ObservableProcessorEvaluator implements
    PaymentReconciliationTechnicalEvaluatorInterface
{
    public function __construct(
        private readonly string $owner,
        private readonly string $entryFile,
        private readonly string $continueFile
    ) {
    }

    public function evaluate(
        ReconciliationReferences $reconciliation,
        DurablePaymentOrigin $origin,
        ValidatedFinancialResult $financialResult
    ): TechnicalReconciliationResult {
        file_put_contents($this->entryFile, $this->owner . PHP_EOL, FILE_APPEND | LOCK_EX);
        $deadline = microtime(true) + 10.0;

        while (! is_file($this->continueFile)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('processor-body-timeout');
            }

            usleep(10000);
            clearstatcache(true, $this->continueFile);
        }

        return (new PaymentReconciliationTechnicalEvaluator())->evaluate(
            $reconciliation,
            $origin,
            $financialResult
        );
    }
}

[
    $script,
    $reconciliationId,
    $owner,
    $barrier,
    $ready,
    $entryFile,
    $continueFile,
] = array_pad($argv, 7, null);

if (
    ! ctype_digit((string) $reconciliationId)
    || ! is_string($owner)
    || ! is_string($barrier)
    || ! is_string($ready)
    || ! is_string($entryFile)
    || ! is_string($continueFile)
) {
    fwrite(STDERR, 'Argumentos de worker invalidos.' . PHP_EOL);
    exit(2);
}

file_put_contents($ready, 'ready', LOCK_EX);
$deadline = microtime(true) + 10.0;

while (! is_file($barrier)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, 'Timeout esperando barrera.' . PHP_EOL);
        exit(3);
    }

    usleep(10000);
    clearstatcache(true, $barrier);
}

try {
    $claims = new PaymentReconciliationClaimRepository();
    $acquired = $claims->acquireLease((int) $reconciliationId, $owner, 30);
    $processingStatus = null;

    if ($acquired->acquired()) {
        $lease = $acquired->lease();

        if ($lease === null) {
            throw new RuntimeException('Lease adquirido ausente.');
        }

        $processingStatus = (new PaymentReconciliationProcessor(
            evaluator: new ObservableProcessorEvaluator(
                $owner,
                $entryFile,
                $continueFile
            )
        ))->process($lease)->status();
    }

    echo json_encode([
        'owner' => $owner,
        'acquire_status' => $acquired->status(),
        'processing_status' => $processingStatus,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . PHP_EOL);
    exit(4);
}
