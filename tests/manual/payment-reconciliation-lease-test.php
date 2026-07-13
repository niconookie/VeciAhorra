<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Migrations\CreatePaymentReconciliationsTable;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\LeaseAcquireResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\LeaseReleaseResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\LeaseRenewResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\StatusTransitionResult;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationClaimRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertReconciliationLease(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string, mixed> $overrides */
function createLeaseFixture(array $overrides = []): int
{
    global $wpdb;

    $nonce = bin2hex(random_bytes(12));
    $now = gmdate('Y-m-d H:i:s');
    $row = array_replace([
        'public_id' => 'pr_' . substr(hash('sha256', 'public-' . $nonce), 0, 40),
        'webpay_return_id' => random_int(1000000, 2000000000),
        'origin_context_id' => random_int(1000000, 2000000000),
        'provider' => 'webpay_plus',
        'fingerprint_version' => 1,
        'financial_fingerprint' => hash('sha256', 'financial-' . $nonce),
        'site_scope' => 'site-lease-test',
        'origin' => 'woocommerce',
        'origin_resource_id' => (string) random_int(1000000, 2000000000),
        'gateway_id' => 'veciahorra_webpay_plus',
        'payment_attempt_id' => 'attempt-' . $nonce,
        'origin_key' => hash('sha256', 'origin-' . $nonce),
        'reconciliation_status' => PaymentReconciliation::STATUS_PENDING,
        'business_result_code' => null,
        'attempt_count' => 0,
        'last_error_code' => null,
        'last_error_at' => null,
        'created_at' => $now,
        'last_attempt_at' => null,
        'reconciled_at' => null,
        'updated_at' => $now,
    ], $overrides);
    $inserted = $wpdb->insert(
        $wpdb->prefix . Config::TABLE_PREFIX . 'payment_reconciliations',
        $row
    );

    if ($inserted !== 1) {
        throw new RuntimeException('No se creo fixture de lease.');
    }

    return (int) $wpdb->insert_id;
}

global $wpdb;

(new CreatePaymentReconciliationsTable())->up();
$repository = new PaymentReconciliationClaimRepository();
$table = $wpdb->prefix . Config::TABLE_PREFIX . 'payment_reconciliations';
$ids = [];
$ownerA = PaymentReconciliationClaimRepository::ownerId();
$ownerB = PaymentReconciliationClaimRepository::ownerId();
$ownerC = PaymentReconciliationClaimRepository::ownerId();

try {
    foreach ([0, -1, true, 1.0, '1', NAN, INF, [], new stdClass()] as $invalid) {
        try {
            $repository->acquireLease(1, $ownerA, $invalid);
            throw new RuntimeException('Se acepto una duracion de lease invalida.');
        } catch (InvalidArgumentException) {
        }
    }

    try {
        $repository->acquireLease(1, 'worker_invalid', 5);
        throw new RuntimeException('Se acepto un propietario no opaco.');
    } catch (InvalidArgumentException) {
    }

    try {
        $repository->compareAndSetStatus(
            1,
            $ownerA,
            1,
            PaymentReconciliation::STATUS_PENDING,
            PaymentReconciliation::STATUS_COMPLETED
        );
        throw new RuntimeException('Se acepto una transicion CAS no documentada.');
    } catch (InvalidArgumentException) {
    }

    assertReconciliationLease(
        $repository->acquireLease(PHP_INT_MAX, $ownerA, 5)->status()
            === LeaseAcquireResult::NOT_FOUND,
        'No se distinguio una conciliacion inexistente.'
    );

    $id = createLeaseFixture();
    $ids[] = $id;
    $first = $repository->acquireLease($id, $ownerA, 5);
    $firstLease = $first->lease();
    $afterFirst = $repository->findLease($id);
    assertReconciliationLease(
        $first->status() === LeaseAcquireResult::ACQUIRED
        && $firstLease !== null
        && $firstLease->reconciliationId() === $id
        && $firstLease->owner() === $ownerA
        && $firstLease->version() === 1
        && $firstLease->expiresAt() !== ''
        && $afterFirst?->owner() === $ownerA
        && $afterFirst->attemptCount() === 1
        && $afterFirst->version() === 1,
        'El primer lease no fue adquirido de forma coherente.'
    );
    $firstExpiry = $afterFirst->expiresAt();
    $firstVersion = $firstLease->version();

    foreach ([0, -1] as $invalidVersion) {
        foreach (['renew', 'release', 'cas'] as $operation) {
            try {
                if ($operation === 'renew') {
                    $repository->renewLease($id, $ownerA, $invalidVersion, 5);
                } elseif ($operation === 'release') {
                    $repository->releaseLease($id, $ownerA, $invalidVersion);
                } else {
                    $repository->compareAndSetStatus(
                        $id,
                        $ownerA,
                        $invalidVersion,
                        PaymentReconciliation::STATUS_PROCESSING,
                        PaymentReconciliation::STATUS_COMPLETED
                    );
                }

                throw new RuntimeException('Se acepto lease_version no positivo.');
            } catch (InvalidArgumentException) {
            }
        }
    }

    assertReconciliationLease(
        $repository->acquireLease($id, $ownerA, 5)->status()
            === LeaseAcquireResult::BUSY
        && $repository->acquireLease($id, $ownerB, 5)->status()
            === LeaseAcquireResult::BUSY
        && $repository->findLease($id)?->expiresAt() === $firstExpiry
        && $repository->findLease($id)?->attemptCount() === 1,
        'Un reintento altero un lease vigente.'
    );
    assertReconciliationLease(
        $repository->renewLease($id, $ownerB, $firstVersion, 5)->status()
            === LeaseRenewResult::NOT_OWNER,
        'Un propietario incorrecto renovo el lease.'
    );
    assertReconciliationLease(
        $repository->renewLease($id, $ownerA, $firstVersion + 99, 5)->status()
            === LeaseRenewResult::VERSION_MISMATCH
        && $repository->releaseLease($id, $ownerA, $firstVersion + 99)->status()
            === LeaseReleaseResult::VERSION_MISMATCH
        && $repository->compareAndSetStatus(
            $id,
            $ownerA,
            $firstVersion + 99,
            PaymentReconciliation::STATUS_PROCESSING,
            PaymentReconciliation::STATUS_COMPLETED
        )->status() === StatusTransitionResult::VERSION_MISMATCH
        && $repository->findLease($id)?->owner() === $ownerA
        && $repository->findLease($id)?->version() === $firstVersion,
        'Una version incorrecta vigente conservo autoridad.'
    );
    $renewed = $repository->renewLease($id, $ownerA, $firstVersion, 20);
    $renewedAgain = $repository->renewLease($id, $ownerA, $firstVersion, 20);
    $renewedThird = $repository->renewLease($id, $ownerA, $firstVersion, 20);
    $afterRenewals = $repository->findLease($id);
    assertReconciliationLease(
        $renewed->status() === LeaseRenewResult::RENEWED
        && $renewed->expiresAt() > $firstExpiry
        && $renewedAgain->status() === LeaseRenewResult::RENEWED
        && $renewedThird->status() === LeaseRenewResult::RENEWED
        && strtotime((string) $afterRenewals?->expiresAt())
            <= strtotime((string) $afterRenewals?->databaseNow()) + 21
        && strtotime((string) $afterRenewals?->expiresAt())
            >= strtotime((string) $afterRenewals?->databaseNow()) + 19
        && $repository->findLease($id)?->attemptCount() === 1
        && $repository->findLease($id)?->version() === 1
        && $repository->acquireLease($id, $ownerB, 5)->status()
            === LeaseAcquireResult::BUSY,
        'La renovacion no extendio exclusivamente el lease propio.'
    );
    assertReconciliationLease(
        $repository->releaseLease($id, $ownerB, $firstVersion)->status()
            === LeaseReleaseResult::NOT_OWNER
        && $repository->releaseLease($id, $ownerA, $firstVersion)->status()
            === LeaseReleaseResult::RELEASED
        && $repository->releaseLease($id, $ownerA, $firstVersion)->status()
            === LeaseReleaseResult::ALREADY_RELEASED,
        'La liberacion no fue segura e idempotente.'
    );

    $secondAcquire = $repository->acquireLease($id, $ownerB, 5);
    $secondVersion = (int) $secondAcquire->leaseVersion();
    assertReconciliationLease(
        $secondAcquire->status() === LeaseAcquireResult::ACQUIRED
        && $secondVersion === $firstVersion + 1
        && $repository->releaseLease($id, $ownerA, $firstVersion)->status()
            === LeaseReleaseResult::NOT_OWNER
        && $repository->findLease($id)?->owner() === $ownerB
        && $repository->renewLease($id, $ownerA, $firstVersion, 5)->status()
            === LeaseRenewResult::NOT_OWNER
        && $repository->compareAndSetStatus(
            $id,
            $ownerA,
            $firstVersion,
            PaymentReconciliation::STATUS_PROCESSING,
            PaymentReconciliation::STATUS_RETRYABLE
        )->status() === StatusTransitionResult::NOT_OWNER,
        'El propietario obsoleto conservo autoridad.'
    );
    $applied = $repository->compareAndSetStatus(
        $id,
        $ownerB,
        $secondVersion,
        PaymentReconciliation::STATUS_PROCESSING,
        PaymentReconciliation::STATUS_RETRYABLE,
        null,
        'temporary_failure'
    );
    $repeated = $repository->compareAndSetStatus(
        $id,
        $ownerB,
        $secondVersion,
        PaymentReconciliation::STATUS_PROCESSING,
        PaymentReconciliation::STATUS_RETRYABLE,
        null,
        'temporary_failure'
    );
    $sameStateDifferentEvidence = $repository->compareAndSetStatus(
        $id,
        $ownerB,
        $secondVersion,
        PaymentReconciliation::STATUS_PROCESSING,
        PaymentReconciliation::STATUS_RETRYABLE,
        'different_result',
        'different_failure'
    );
    assertReconciliationLease(
        $applied->status() === StatusTransitionResult::APPLIED
        && $repeated->status() === StatusTransitionResult::ALREADY_APPLIED
        && $sameStateDifferentEvidence->status()
            === StatusTransitionResult::UNEXPECTED_STATE
        && $repository->findLease($id)?->owner() === null,
        'El CAS repetido no fue logicamente idempotente.'
    );

    $expiredId = createLeaseFixture();
    $ids[] = $expiredId;
    $expiringAcquire = $repository->acquireLease($expiredId, $ownerA, 1);
    $expiringVersion = (int) $expiringAcquire->leaseVersion();
    assertReconciliationLease(
        $expiringAcquire->acquired() && $expiringVersion === 1,
        'A no adquirio el lease corto.'
    );
    $deadline = microtime(true) + 6.0;

    do {
        $expiredLease = $repository->findLease($expiredId);

        if ($expiredLease !== null && ! $expiredLease->active()) {
            break;
        }

        usleep(100000);
    } while (microtime(true) < $deadline);

    assertReconciliationLease(
        isset($expiredLease) && ! $expiredLease->active(),
        'El lease no expiro dentro del timeout controlado.'
    );
    assertReconciliationLease(
        $repository->renewLease($expiredId, $ownerA, $expiringVersion, 5)->status()
            === LeaseRenewResult::EXPIRED
        && $repository->releaseLease(
            $expiredId,
            $ownerA,
            $expiringVersion
        )->status() === LeaseReleaseResult::EXPIRED
        && $repository->compareAndSetStatus(
            $expiredId,
            $ownerA,
            $expiringVersion,
            PaymentReconciliation::STATUS_PROCESSING,
            PaymentReconciliation::STATUS_RETRYABLE
        )->status() === StatusTransitionResult::LEASE_EXPIRED
        && $repository->acquireLease($expiredId, $ownerB, 5)->acquired(),
        'Un lease expirado no fue recuperado correctamente.'
    );
    $recoveredVersion = $repository->findLease($expiredId)?->version();
    assertReconciliationLease(
        $recoveredVersion === 2
        && $repository->renewLease(
            $expiredId,
            $ownerA,
            $expiringVersion,
            5
        )->status()
            === LeaseRenewResult::NOT_OWNER
        && $repository->releaseLease(
            $expiredId,
            $ownerA,
            $expiringVersion
        )->status()
            === LeaseReleaseResult::NOT_OWNER
        && $repository->compareAndSetStatus(
            $expiredId,
            $ownerA,
            $expiringVersion,
            PaymentReconciliation::STATUS_PROCESSING,
            PaymentReconciliation::STATUS_COMPLETED
        )->status() === StatusTransitionResult::NOT_OWNER,
        'A pudo operar despues de la recuperacion por B.'
    );

    $abaId = createLeaseFixture();
    $ids[] = $abaId;
    $abaFirst = $repository->acquireLease($abaId, $ownerA, 1);
    $abaVersionOne = (int) $abaFirst->leaseVersion();
    $abaDeadline = microtime(true) + 6.0;

    do {
        $abaExpired = $repository->findLease($abaId);

        if ($abaExpired !== null && ! $abaExpired->active()) {
            break;
        }

        usleep(100000);
    } while (microtime(true) < $abaDeadline);

    assertReconciliationLease(
        $abaVersionOne === 1
        && isset($abaExpired)
        && ! $abaExpired->active(),
        'No se preparo la primera generacion ABA.'
    );
    $abaSecond = $repository->acquireLease($abaId, $ownerA, 10);
    $abaVersionTwo = (int) $abaSecond->leaseVersion();
    $abaBeforeStaleOperations = $repository->findLease($abaId);
    assertReconciliationLease(
        $abaVersionTwo === 2
        && $repository->renewLease(
            $abaId,
            $ownerA,
            $abaVersionOne,
            10
        )->status() === LeaseRenewResult::VERSION_MISMATCH
        && $repository->releaseLease(
            $abaId,
            $ownerA,
            $abaVersionOne
        )->status() === LeaseReleaseResult::VERSION_MISMATCH
        && $repository->compareAndSetStatus(
            $abaId,
            $ownerA,
            $abaVersionOne,
            PaymentReconciliation::STATUS_PROCESSING,
            PaymentReconciliation::STATUS_COMPLETED
        )->status() === StatusTransitionResult::VERSION_MISMATCH,
        'Una operacion ABA con version 1 obtuvo autoridad sobre version 2.'
    );
    $abaAfterStaleOperations = $repository->findLease($abaId);
    assertReconciliationLease(
        $abaAfterStaleOperations?->owner() === $ownerA
        && $abaAfterStaleOperations->version() === $abaVersionTwo
        && $abaAfterStaleOperations->expiresAt()
            === $abaBeforeStaleOperations?->expiresAt()
        && $abaAfterStaleOperations->reconciliationStatus()
            === PaymentReconciliation::STATUS_PROCESSING,
        'Las operaciones obsoletas alteraron el lease ABA version 2.'
    );

    $terminalId = createLeaseFixture([
        'reconciliation_status' => PaymentReconciliation::STATUS_COMPLETED,
        'reconciled_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $ids[] = $terminalId;
    assertReconciliationLease(
        $repository->acquireLease($terminalId, $ownerC, 5)->status()
            === LeaseAcquireResult::NOT_CLAIMABLE,
        'Se reclamo un estado terminal.'
    );

    $exhaustedId = createLeaseFixture(['attempt_count' => 5]);
    $ids[] = $exhaustedId;
    assertReconciliationLease(
        $repository->acquireLease($exhaustedId, $ownerC, 5)->status()
            === LeaseAcquireResult::ATTEMPTS_EXHAUSTED
        && $repository->findLease($exhaustedId)?->reconciliationStatus()
            === PaymentReconciliation::STATUS_MANUAL_REVIEW,
        'Se supero el maximo durable de intentos.'
    );

    assertReconciliationLease(
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE id IN ("
            . implode(', ', array_fill(0, count($ids), '%d')) . ')',
            ...$ids
        )) === count($ids),
        'Los reintentos crearon o eliminaron conciliaciones.'
    );

    $renewalWindow = strtotime((string) $afterRenewals?->expiresAt())
        - strtotime((string) $afterRenewals?->databaseNow());
    echo 'PASS payment-reconciliation-lease-test'
        . " aba_versions={$abaVersionOne}->{$abaVersionTwo}"
        . " renewal_window_seconds={$renewalWindow}\n";
} finally {
    foreach ($ids as $fixtureId) {
        $wpdb->delete($table, ['id' => $fixtureId], ['%d']);
    }
}
