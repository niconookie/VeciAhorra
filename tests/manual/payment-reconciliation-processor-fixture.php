<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Migrations\CreatePaymentOriginContextsTable;
use VeciAhorra\Database\Migrations\CreatePaymentReconciliationsTable;
use VeciAhorra\Database\Migrations\CreateWebpayReturnsTable;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\CreatePaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\FinancialFingerprintComponents;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\ValidatedFinancialResultRepository;

function migrateProcessorFixtures(): void
{
    (new CreatePaymentOriginContextsTable())->up();
    (new CreateWebpayReturnsTable())->up();
    (new CreatePaymentReconciliationsTable())->up();
}

/** @return array{id:int, origin_id:int, return_id:int} */
function createProcessorFixture(string $suffix = ''): array
{
    $nonce = bin2hex(random_bytes(10)) . $suffix;
    $now = gmdate('Y-m-d H:i:s');
    $attempt = 'attempt-' . substr(hash('sha256', $nonce), 0, 24);
    $buyOrder = 'VA' . strtoupper(substr(hash('sha256', 'buy-' . $nonce), 0, 24));
    $sessionId = 'VA-' . strtoupper(substr(hash('sha256', 'session-' . $nonce), 0, 58));
    $tokenHash = hash('sha256', 'token-' . $nonce);
    $merchantHash = hash('sha256', 'merchant-processor-test');
    $origin = new DurablePaymentOrigin(
        'poc_' . substr(hash('sha256', 'origin-' . $nonce), 0, 40),
        'site-processor-test',
        DurablePaymentOrigin::ORIGIN_WOOCOMMERCE,
        (string) random_int(100000, 999999999),
        'veciahorra_webpay_plus',
        $attempt,
        15990,
        'integration',
        $merchantHash,
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
        $merchantHash,
        'AUTHORIZED',
        0,
        15990,
        $buyOrder,
        $sessionId,
        '2026-07-13T16:30:00Z',
        hash('sha256', 'authorization-' . $nonce),
        'VD',
        0,
        '0713'
    );
    $financial = new ValidatedFinancialResult(
        'wpr_' . substr(hash('sha256', 'return-' . $nonce), 0, 40),
        'approved',
        'commit',
        $tokenHash,
        'sha256:' . substr(hash('sha256', 'safe-' . $nonce), 0, 16),
        $components,
        $now,
        $now
    );
    $origins = new PaymentOriginContextRepository();
    $returns = new ValidatedFinancialResultRepository();
    $originId = $origins->create($origin);
    $returnId = $returns->create($financial);
    $id = (new PaymentReconciliationRepository($origins, $returns))->create(
        new CreatePaymentReconciliation(
            'pr_' . substr(hash('sha256', 'reconciliation-' . $nonce), 0, 40),
            $returnId,
            $originId,
            $financial,
            $origin,
            PaymentReconciliation::STATUS_PENDING,
            null,
            0,
            null,
            null,
            $now,
            null,
            null,
            $now
        )
    );

    return ['id' => $id, 'origin_id' => $originId, 'return_id' => $returnId];
}

/** @param array{id:int, origin_id:int, return_id:int} $fixture */
function deleteProcessorFixture(array $fixture): void
{
    global $wpdb;

    $wpdb->delete(
        $wpdb->prefix . Config::TABLE_PREFIX . 'payment_reconciliations',
        ['id' => $fixture['id']],
        ['%d']
    );
    $wpdb->delete(
        $wpdb->prefix . Config::TABLE_PREFIX . 'webpay_returns',
        ['id' => $fixture['return_id']],
        ['%d']
    );
    $wpdb->delete(
        $wpdb->prefix . Config::TABLE_PREFIX . 'payment_origin_contexts',
        ['id' => $fixture['origin_id']],
        ['%d']
    );
}
