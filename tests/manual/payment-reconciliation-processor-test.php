<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentReconciliationTechnicalEvaluatorInterface;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\ReconciliationClockInterface;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\LeaseAcquireResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\PaymentReconciliationProcessingResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationLease;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationReferences;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\StatusTransitionResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\TechnicalReconciliationResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationClaimRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Service\PaymentReconciliationProcessor;

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once __DIR__ . '/payment-reconciliation-processor-fixture.php';
require_once __DIR__ . '/payment-reconciliation-test-completion-handler.php';

final class ThrowingProcessorEvaluator implements PaymentReconciliationTechnicalEvaluatorInterface
{
    public function evaluate(
        ReconciliationReferences $reconciliation,
        DurablePaymentOrigin $origin,
        ValidatedFinancialResult $financialResult
    ): TechnicalReconciliationResult {
        throw new RuntimeException('controlled-test-error');
    }
}

final class SequenceProcessorClock implements ReconciliationClockInterface
{
    /** @param non-empty-list<int> $times */
    public function __construct(private array $times)
    {
    }

    public function now(): int
    {
        if (count($this->times) > 1) {
            return (int) array_shift($this->times);
        }

        return (int) $this->times[0];
    }
}

final class ExpiringProcessorEvaluator implements PaymentReconciliationTechnicalEvaluatorInterface
{
    public function __construct(
        private readonly PaymentReconciliationClaimRepository $claims,
        private readonly PaymentReconciliationTechnicalEvaluatorInterface $delegate,
        private readonly int $id
    ) {
    }

    public function evaluate(
        ReconciliationReferences $reconciliation,
        DurablePaymentOrigin $origin,
        ValidatedFinancialResult $financialResult
    ): TechnicalReconciliationResult {
        $deadline = microtime(true) + 6.0;

        do {
            $state = $this->claims->findLease($this->id);

            if ($state !== null && ! $state->active()) {
                return $this->delegate->evaluate(
                    $reconciliation,
                    $origin,
                    $financialResult
                );
            }

            usleep(100000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('lease-did-not-expire');
    }
}

function assertProcessor(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

global $wpdb;

migrateProcessorFixtures();
$claims = new PaymentReconciliationClaimRepository();
$fixtures = [];
$reconciliationTable = $wpdb->prefix . Config::TABLE_PREFIX . 'payment_reconciliations';
$originTable = $wpdb->prefix . Config::TABLE_PREFIX . 'payment_origin_contexts';
$returnTable = $wpdb->prefix . Config::TABLE_PREFIX . 'webpay_returns';

try {
    $success = createProcessorFixture('success');
    $fixtures[] = $success;
    $owner = PaymentReconciliationClaimRepository::ownerId();
    $acquired = $claims->acquireLease($success['id'], $owner, 60);
    $lease = $acquired->lease();
    assertProcessor(
        $acquired->status() === LeaseAcquireResult::ACQUIRED && $lease !== null,
        'No se adquirio el lease para procesamiento exitoso.'
    );
    $invalidExpiryLease = new ReconciliationLease(
        $lease->reconciliationId(),
        $lease->owner(),
        $lease->version(),
        gmdate('Y-m-d H:i:s', strtotime($lease->expiresAt()) + 1)
    );
    assertProcessor(
        (new PaymentReconciliationProcessor(
            completionHandler: new TechnicalOnlyCompletionHandler()
        ))->process($invalidExpiryLease)->status()
            === PaymentReconciliationProcessingResult::INVALID_LEASE,
        'Se acepto una capacidad cuyo vencimiento no coincide con la fila.'
    );
    $processed = (new PaymentReconciliationProcessor(
        completionHandler: new TechnicalOnlyCompletionHandler()
    ))->process($lease);
    $completed = $claims->findLease($success['id']);
    assertProcessor(
        $processed->status() === PaymentReconciliationProcessingResult::PROCESSED
        && $processed->technicalResult()?->resultCode() === 'technical_approved'
        && $completed?->reconciliationStatus() === PaymentReconciliation::STATUS_COMPLETED
        && $completed->businessResultCode() === 'technical_approved'
        && $completed->owner() === null,
        'El cierre tecnico exitoso no quedo consistente.'
    );
    $second = (new PaymentReconciliationProcessor(
        completionHandler: new TechnicalOnlyCompletionHandler()
    ))->process($lease);
    assertProcessor(
        $second->status() === PaymentReconciliationProcessingResult::NOT_PROCESSABLE
        && $claims->acquireLease($success['id'], $owner, 10)->status()
            === LeaseAcquireResult::NOT_CLAIMABLE,
        'El CAS final pudo aplicarse o reclamarse por segunda vez.'
    );

    $heartbeat = createProcessorFixture('heartbeat');
    $fixtures[] = $heartbeat;
    $heartbeatOwner = PaymentReconciliationClaimRepository::ownerId();
    $heartbeatLease = $claims->acquireLease(
        $heartbeat['id'],
        $heartbeatOwner,
        60
    )->lease();
    assertProcessor($heartbeatLease !== null, 'No se preparo heartbeat exitoso.');
    $heartbeatResult = (new PaymentReconciliationProcessor(
        completionHandler: new TechnicalOnlyCompletionHandler(),
        clock: new SequenceProcessorClock([100, 106]),
        heartbeatThresholdSeconds: 5,
        heartbeatLeaseSeconds: 60
    ))->process($heartbeatLease);
    assertProcessor(
        $heartbeatResult->processed()
        && $heartbeatResult->heartbeatPerformed()
        && $claims->findLease($heartbeat['id'])?->version()
            === $heartbeatLease->version(),
        'El heartbeat no conservo owner/version para el CAS.'
    );

    $failure = createProcessorFixture('failure');
    $fixtures[] = $failure;
    $failureOwner = PaymentReconciliationClaimRepository::ownerId();
    $failureLease = $claims->acquireLease($failure['id'], $failureOwner, 60)->lease();
    assertProcessor($failureLease !== null, 'No se preparo el fallo controlado.');
    $failed = (new PaymentReconciliationProcessor(
        evaluator: new ThrowingProcessorEvaluator(),
        completionHandler: new TechnicalOnlyCompletionHandler()
    ))->process($failureLease);
    $failedState = $claims->findLease($failure['id']);
    assertProcessor(
        $failed->status() === PaymentReconciliationProcessingResult::RECOVERABLE_ERROR
        && $failedState?->reconciliationStatus() === PaymentReconciliation::STATUS_RETRYABLE
        && $failedState->lastErrorCode() === 'technical_internal_error'
        && $failedState->owner() === null
        && $claims->acquireLease(
            $failure['id'],
            PaymentReconciliationClaimRepository::ownerId(),
            10
        )->acquired(),
        'El error previo al CAS no quedo recuperable.'
    );

    foreach ([
        ['kind' => 'origin', 'status' => PaymentReconciliationProcessingResult::ORIGIN_CONTEXT_MISSING],
        ['kind' => 'financial', 'status' => PaymentReconciliationProcessingResult::FINANCIAL_EVIDENCE_MISSING],
    ] as $missingCase) {
        $missing = createProcessorFixture('missing-' . $missingCase['kind']);
        $fixtures[] = $missing;
        $missingLease = $claims->acquireLease(
            $missing['id'],
            PaymentReconciliationClaimRepository::ownerId(),
            60
        )->lease();
        assertProcessor($missingLease !== null, 'No se preparo evidencia ausente.');

        if ($missingCase['kind'] === 'origin') {
            $wpdb->delete($originTable, ['id' => $missing['origin_id']], ['%d']);
        } else {
            $wpdb->delete($returnTable, ['id' => $missing['return_id']], ['%d']);
        }

        $missingResult = (new PaymentReconciliationProcessor(
            completionHandler: new TechnicalOnlyCompletionHandler()
        ))->process($missingLease);
        assertProcessor(
            $missingResult->status() === $missingCase['status']
            && $claims->findLease($missing['id'])?->reconciliationStatus()
                === PaymentReconciliation::STATUS_RETRYABLE,
            'La evidencia ausente no produjo recuperacion determinista.'
        );
    }

    $inconsistent = createProcessorFixture('inconsistent');
    $fixtures[] = $inconsistent;
    $inconsistentLease = $claims->acquireLease(
        $inconsistent['id'],
        PaymentReconciliationClaimRepository::ownerId(),
        60
    )->lease();
    assertProcessor($inconsistentLease !== null, 'No se preparo inconsistencia.');
    $wpdb->update(
        $reconciliationTable,
        ['origin_key' => hash('sha256', 'tampered-origin-key')],
        ['id' => $inconsistent['id']],
        ['%s'],
        ['%d']
    );
    $inconsistentResult = (new PaymentReconciliationProcessor(
        completionHandler: new TechnicalOnlyCompletionHandler()
    ))->process(
        $inconsistentLease
    );
    assertProcessor(
        $inconsistentResult->status()
            === PaymentReconciliationProcessingResult::INCONSISTENT_EVIDENCE
        && $claims->findLease($inconsistent['id'])?->reconciliationStatus()
            === PaymentReconciliation::STATUS_RETRYABLE,
        'La evidencia inconsistente fue procesada.'
    );

    $expiry = createProcessorFixture('expiry');
    $fixtures[] = $expiry;
    $expiryOwner = PaymentReconciliationClaimRepository::ownerId();
    $expiryLease = $claims->acquireLease($expiry['id'], $expiryOwner, 1)->lease();
    assertProcessor($expiryLease !== null, 'No se preparo lease expirable.');
    $defaultEvaluator = new \VeciAhorra\Modules\Payments\Reconciliation\Service\PaymentReconciliationTechnicalEvaluator();
    $expiredResult = (new PaymentReconciliationProcessor(
        evaluator: new ExpiringProcessorEvaluator(
            $claims,
            $defaultEvaluator,
            $expiry['id']
        ),
        completionHandler: new TechnicalOnlyCompletionHandler(),
        heartbeatThresholdSeconds: 30,
        heartbeatLeaseSeconds: 30
    ))->process($expiryLease);
    assertProcessor(
        $expiredResult->status()
            === PaymentReconciliationProcessingResult::HEARTBEAT_REJECTED
        && $claims->findLease($expiry['id'])?->reconciliationStatus()
            === PaymentReconciliation::STATUS_PROCESSING,
        'La expiracion no detuvo el procesador antes del CAS.'
    );
    assertProcessor(
        $claims->compareAndSetStatus(
            $expiry['id'],
            $expiryOwner,
            $expiryLease->version(),
            PaymentReconciliation::STATUS_PROCESSING,
            PaymentReconciliation::STATUS_COMPLETED,
            'technical_approved'
        )->status() === StatusTransitionResult::LEASE_EXPIRED,
        'El CAS expirado no afecto cero filas.'
    );
    $replacement = $claims->acquireLease($expiry['id'], $expiryOwner, 60)->lease();
    assertProcessor(
        $replacement !== null
        && $expiryLease->version() === 1
        && $replacement->version() === 2,
        'No se produjo la nueva generacion ABA.'
    );
    $stale = (new PaymentReconciliationProcessor(
        completionHandler: new TechnicalOnlyCompletionHandler()
    ))->process($expiryLease);
    $afterStale = $claims->findLease($expiry['id']);
    assertProcessor(
        $stale->status() === PaymentReconciliationProcessingResult::AUTHORITY_LOST
        && $afterStale?->owner() === $expiryOwner
        && $afterStale->version() === 2
        && $afterStale->reconciliationStatus() === PaymentReconciliation::STATUS_PROCESSING,
        'La autoridad version 1 modifico el lease version 2.'
    );

    echo 'PASS payment-reconciliation-processor-test'
        . ' aba_versions=1->2 heartbeat=renewed expiry=authority_lost' . PHP_EOL;
} finally {
    foreach (array_reverse($fixtures) as $fixture) {
        deleteProcessorFixture($fixture);
    }
}
