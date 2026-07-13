<?php

declare(strict_types=1);

use VeciAhorra\Database\Migrations\CreatePaymentOriginContextsTable;
use VeciAhorra\Database\Migrations\CreatePaymentReconciliationsTable;
use VeciAhorra\Database\Migrations\CreateWebpayReturnsTable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\CreatePaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\FinancialFingerprintComponents;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\Exception\DuplicateReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Exception\DuplicatePaymentOriginContext;
use VeciAhorra\Modules\Payments\Reconciliation\Exception\DuplicateValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\ValidatedFinancialResultRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Support\DatabaseErrorClassifier;
use VeciAhorra\Modules\Payments\Repository\WebpayReturnRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertReconciliationRepository(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

global $wpdb;

(new CreatePaymentOriginContextsTable())->up();
(new CreateWebpayReturnsTable())->up();
(new CreatePaymentReconciliationsTable())->up();

$origins = new PaymentOriginContextRepository();
$financialResults = new ValidatedFinancialResultRepository();
$reconciliations = new PaymentReconciliationRepository($origins, $financialResults);
$nonce = bin2hex(random_bytes(8));
$now = current_time('mysql');
$statuses = [
    PaymentReconciliation::STATUS_PENDING,
    PaymentReconciliation::STATUS_PROCESSING,
    PaymentReconciliation::STATUS_COMPLETED,
    PaymentReconciliation::STATUS_RETRYABLE,
    PaymentReconciliation::STATUS_PERMANENT_FAILURE,
    PaymentReconciliation::STATUS_MANUAL_REVIEW,
];

assertReconciliationRepository(
    $wpdb->query('START TRANSACTION') !== false,
    'No fue posible iniciar la transaccion de prueba.'
);

try {
    $created = [];
    $legacyReturns = new WebpayReturnRepository();
    $legacyTokenHash = hash('sha256', 'legacy-safe-fixture-' . $nonce);
    $legacyClaim = $legacyReturns->claim(
        $legacyTokenHash,
        null,
        'commit',
        $now
    );
    $legacyRow = $legacyReturns->find($legacyTokenHash);

    assertReconciliationRepository(
        ($legacyClaim['claimed'] ?? false) === true
        && is_array($legacyRow)
        && array_key_exists('financial_status', $legacyRow)
        && $legacyRow['financial_status'] === null
        && $legacyRow['financial_fingerprint'] === null
        && $legacyRow['financial_validated_at'] === null,
        'El repository Webpay existente no conservo columnas nuevas NULL.'
    );

    try {
        $financialResults->find((int) $legacyRow['id']);
        throw new RuntimeException(
            'Una fila historica NULL fue interpretada como evidencia validada.'
        );
    } catch (PersistenceException) {
    }

    foreach ($statuses as $index => $status) {
        $amount = 10000 + $index;
        $attempt = 'attempt-' . $nonce . '-' . $index;
        $buyOrder = 'VA' . strtoupper(substr(hash('sha256', $attempt), 0, 24));
        $sessionId = 'VA-' . strtoupper(substr(
            hash('sha256', 'session-' . $attempt),
            0,
            58
        ));
        $tokenHash = hash('sha256', 'fixture-financial-reference-' . $attempt);
        $origin = new DurablePaymentOrigin(
            'poc_' . substr(hash('sha256', $attempt), 0, 40),
            'site-1',
            DurablePaymentOrigin::ORIGIN_WOOCOMMERCE,
            (string) (990000 + $index),
            'veciahorra_webpay_plus',
            $attempt,
            $amount,
            'integration',
            hash('sha256', 'merchant-test'),
            $buyOrder,
            $sessionId,
            $tokenHash,
            1,
            $now,
            $now,
            gmdate('Y-m-d H:i:s', time() + 3600)
        );
        $components = new FinancialFingerprintComponents(
            'integration',
            hash('sha256', 'merchant-test'),
            $status === PaymentReconciliation::STATUS_COMPLETED
                ? 'AUTHORIZED'
                : 'FAILED',
            $status === PaymentReconciliation::STATUS_COMPLETED ? 0 : -1,
            $amount,
            $buyOrder,
            $sessionId,
            '2026-07-13T16:30:00Z',
            $status === PaymentReconciliation::STATUS_COMPLETED
                ? hash('sha256', 'fixture-approval-' . $attempt)
                : null,
            'VD',
            0,
            '0713'
        );
        $financial = new ValidatedFinancialResult(
            'wpr_' . substr(hash('sha256', 'result-' . $attempt), 0, 40),
            $status === PaymentReconciliation::STATUS_COMPLETED
                ? 'approved'
                : 'rejected',
            'commit',
            $tokenHash,
            'sha256:' . substr(hash('sha256', 'safe-' . $attempt), 0, 16),
            $components,
            $now,
            $now
        );
        $originId = $origins->create($origin);
        $returnId = $financialResults->create($financial);
        $create = new CreatePaymentReconciliation(
            'pr_' . substr(hash('sha256', 'reconciliation-' . $attempt), 0, 40),
            $returnId,
            $originId,
            $financial,
            $origin,
            $status,
            $status === PaymentReconciliation::STATUS_COMPLETED
                ? 'completed'
                : null,
            $index,
            $status === PaymentReconciliation::STATUS_RETRYABLE
                ? 'temporary_failure'
                : null,
            $status === PaymentReconciliation::STATUS_RETRYABLE ? $now : null,
            $now,
            $index > 0 ? $now : null,
            $status === PaymentReconciliation::STATUS_COMPLETED ? $now : null,
            $now
        );
        $id = $reconciliations->create($create);
        $stored = $reconciliations->find($id);

        assertReconciliationRepository(
            $stored instanceof PaymentReconciliation
            && $stored->id() === $id
            && $stored->status() === $status
            && $stored->financialResult()->financialStatus()
                === $financial->financialStatus()
            && $stored->origin()->amountClp() === $amount
            && $stored->origin()->tokenHash() === $tokenHash,
            "No se hidrato completamente el estado {$status}."
        );
        assertReconciliationRepository(
            $reconciliations->findByFingerprint($financial->fingerprint())?->id()
                === $id
            && count($reconciliations->findByOrigin(
                'site-1',
                DurablePaymentOrigin::ORIGIN_WOOCOMMERCE,
                (string) (990000 + $index)
            )) === 1,
            'Las busquedas durables no recuperaron la conciliacion.'
        );

        $created[] = [$create, $financial];
    }

    [$duplicate] = $created[0];
    $wpdb->suppress_errors(true);

    try {
        $origins->create($duplicate->origin());
        throw new RuntimeException('El indice unico acepto un origen duplicado.');
    } catch (DuplicatePaymentOriginContext) {
    }

    try {
        $financialResults->create($created[0][1]);
        throw new RuntimeException('El indice unico acepto evidencia duplicada.');
    } catch (DuplicateValidatedFinancialResult) {
    }

    try {
        $reconciliations->create($duplicate);
        throw new RuntimeException('El indice unico acepto un fingerprint duplicado.');
    } catch (DuplicateReconciliation $exception) {
        assertReconciliationRepository(
            ! str_contains(strtolower($exception->getMessage()), 'insert')
            && ! str_contains($exception->getMessage(), $wpdb->prefix),
            'La colision controlada expuso SQL interno.'
        );
    } finally {
        $wpdb->suppress_errors(false);
    }

    $wpdb->suppress_errors(true);

    try {
        $wpdb->query(sprintf(
            'INSERT INTO %s (columna_inexistente) VALUES (1)',
            $wpdb->prefix . 'va_payment_reconciliations'
        ));
        assertReconciliationRepository(
            ! DatabaseErrorClassifier::isDuplicateKey($wpdb),
            'Un error SQL no duplicado fue clasificado como colision.'
        );
    } finally {
        $wpdb->suppress_errors(false);
    }

    try {
        new CreatePaymentReconciliation(
            'pr_invalid', 1, 1, $created[0][1],
            $created[0][0]->origin(), 'retryable_failure', null, 0,
            null, null, $now, null, null, $now
        );
        throw new RuntimeException('Se acepto un estado no normativo.');
    } catch (InvalidArgumentException) {
    }

    assertReconciliationRepository(
        $reconciliations->find(PHP_INT_MAX) === null
        && $reconciliations->findByFingerprint(str_repeat('0', 64)) === null,
        'La ausencia no se represento como null.'
    );

    echo "PASS payment-reconciliation-repository-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
