<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Migrations\CreateBusinessCompletionsTable;
use VeciAhorra\Database\Migrations\CreatePaymentsTables;
use VeciAhorra\Modules\Payments\BusinessCompletion\DTO\BusinessCompletionResult;
use VeciAhorra\Modules\Payments\BusinessCompletion\Repository\BusinessCompletionRepository;
use VeciAhorra\Modules\Payments\BusinessCompletion\Service\BusinessCompletionProcessor;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\CreatePaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\FinancialFingerprintComponents;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\ValidatedFinancialResultRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function bcAssert(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

global $wpdb;
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
(new CreatePaymentsTables())->up();
(new CreateBusinessCompletionsTable())->up();
$created = [];

try {
    $processor = new BusinessCompletionProcessor();
    bcAssert($processor->process(PHP_INT_MAX, 'business_' . str_repeat('a', 32))->reason === 'reconciliation_missing', 'No rechazo conciliacion inexistente.');

    $nonce = bin2hex(random_bytes(10));
    $now = gmdate('Y-m-d H:i:s');
    $checkoutPublic = 'chk_' . substr(hash('sha256', 'checkout-' . $nonce), 0, 43);
    $sessionPublic = 'ps_' . substr(hash('sha256', 'session-' . $nonce), 0, 43);
    $wpdb->insert($prefix . 'checkouts', [
        'public_id' => $checkoutPublic, 'owner_type' => 'user', 'user_id' => 980001,
        'session_id' => null, 'status' => 'payment_started',
        'fulfillment_method' => 'delivery', 'currency' => 'CLP',
        'total_amount' => '3000.00', 'created_at' => $now, 'updated_at' => $now,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $checkoutId = (int) $wpdb->insert_id; $created['checkout'] = $checkoutId;
    $orderIds = [];
    foreach ([1000, 2000] as $i => $amount) {
        $wpdb->insert($prefix . 'orders', [
            'customer_id' => 980001, 'minimarket_id' => 980100 + $i,
            'total' => $amount . '.00', 'status' => 'reserved',
            'reservation_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $orderIds[] = (int) $wpdb->insert_id;
    }
    $created['orders'] = $orderIds;
    foreach ($orderIds as $orderId) {
        $wpdb->insert($prefix . 'checkout_orders', ['checkout_id' => $checkoutId, 'order_id' => $orderId, 'created_at' => $now]);
    }
    $wpdb->insert($prefix . 'payment_sessions', [
        'public_id' => $sessionPublic, 'checkout_id' => $checkoutId, 'payment_id' => null,
        'idempotency_key' => hash('sha256', 'key-' . $nonce),
        'request_fingerprint' => hash('sha256', 'request-' . $nonce), 'status' => 'ready',
        'provider' => 'webpay_plus', 'provider_session_id' => hash('sha256', 'provider-' . $nonce),
        'redirect_url' => null, 'currency' => 'CLP', 'amount' => '3000.00', 'metadata' => null,
        'created_at' => $now, 'updated_at' => $now, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $created['session'] = (int) $wpdb->insert_id;
    $merchant = hash('sha256', 'merchant-' . $nonce);
    $buyOrder = 'VA' . strtoupper(substr(hash('sha256', 'buy-' . $nonce), 0, 24));
    $financialSession = 'VA-' . strtoupper(substr(hash('sha256', 'financial-' . $nonce), 0, 58));
    $origin = new DurablePaymentOrigin(
        'poc_' . substr(hash('sha256', 'origin-' . $nonce), 0, 40), 'veciahorra:checkout',
        DurablePaymentOrigin::ORIGIN_VECIAHORRA, $checkoutPublic, 'webpay_plus', $sessionPublic,
        3000, 'integration', $merchant, $buyOrder, $financialSession,
        hash('sha256', 'token-' . $nonce), 1, $now, $now, gmdate('Y-m-d H:i:s', time() + 3600)
    );
    $components = new FinancialFingerprintComponents(
        'integration', $merchant, 'AUTHORIZED', 0, 3000, $buyOrder, $financialSession,
        '2026-07-14T12:00:00Z', hash('sha256', 'authorization-' . $nonce), 'VD', 0, '0714'
    );
    $financial = new ValidatedFinancialResult(
        'wpr_' . substr(hash('sha256', 'return-' . $nonce), 0, 40), 'approved', 'commit',
        hash('sha256', 'token-' . $nonce), 'sha256:' . substr(hash('sha256', 'safe-' . $nonce), 0, 16),
        $components, $now, $now
    );
    $origins = new PaymentOriginContextRepository(); $returns = new ValidatedFinancialResultRepository();
    $originId = $origins->create($origin); $returnId = $returns->create($financial);
    $created['origin'] = $originId; $created['return'] = $returnId;
    $reconciliations = new PaymentReconciliationRepository($origins, $returns);
    $reconciliationId = $reconciliations->create(new CreatePaymentReconciliation(
        'pr_' . substr(hash('sha256', 'reconciliation-' . $nonce), 0, 40), $returnId, $originId,
        $financial, $origin, PaymentReconciliation::STATUS_PENDING, null, 0, null, null,
        $now, null, null, $now
    ));
    $created['reconciliation'] = $reconciliationId;
    bcAssert($processor->process($reconciliationId, 'business_' . str_repeat('b', 32))->reason === 'reconciliation_not_completed', 'Proceso conciliacion no completada.');
    $wpdb->update($prefix . 'payment_reconciliations', ['reconciliation_status' => 'completed', 'reconciled_at' => $now], ['id' => $reconciliationId]);
    $completionRepository = new BusinessCompletionRepository();
    $completionKey = hash('sha256', 'business-completion-v1|' . $reconciliationId . '|' . $financial->fingerprint());
    $completion = $completionRepository->ensure($reconciliationId, $completionKey, $now);
    $claim = $completionRepository->acquire((int) $completion['id'], 'business_' . str_repeat('1', 32), $now, gmdate('Y-m-d H:i:s', time() + 30));
    $competingClaim = $completionRepository->acquire((int) $completion['id'], 'business_' . str_repeat('2', 32), $now, gmdate('Y-m-d H:i:s', time() + 30));
    bcAssert($claim !== null && $competingClaim === null && (int) $claim['lease_version'] === 1, 'El claim concurrente no fue exclusivo o versionado.');
    $completionRepository->fail((int) $completion['id'], 'business_' . str_repeat('2', 32), 1, 'retryable', 'wrong_owner', $now);
    bcAssert(($completionRepository->findByReconciliation($reconciliationId)['status'] ?? null) === 'processing', 'Un owner ajeno libero el claim.');
    $completionRepository->fail((int) $completion['id'], 'business_' . str_repeat('1', 32), 1, 'retryable', 'fixture_release', $now);
    $result = $processor->process($reconciliationId, 'business_' . str_repeat('c', 32));
    bcAssert($result->status === BusinessCompletionResult::COMPLETED && $result->paymentId !== null, 'No completo la materializacion.');
    bcAssert((int) ($completionRepository->findByReconciliation($reconciliationId)['lease_version'] ?? 0) === 2, 'El reacquire no incremento la version anti-ABA.');
    $created['payment'] = $result->paymentId;
    $payment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}payments WHERE id = %d", $result->paymentId), ARRAY_A);
    bcAssert($payment['status'] === 'paid' && $payment['financial_fingerprint'] === $financial->fingerprint(), 'Payment no quedo aprobado con evidencia durable.');
    bcAssert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}payment_orders WHERE payment_id = %d", $result->paymentId)) === 2, 'No vinculo multiples Orders.');
    $completionRow = $completionRepository->findByReconciliation($reconciliationId);
    bcAssert(($completionRow['fulfillment_method'] ?? null) === 'delivery', 'BusinessCompletion no sello fulfillment.');
    bcAssert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}business_completion_orders WHERE business_completion_id = %d", (int) $completionRow['id'])) === 2, 'BusinessCompletion no sello todas las Orders.');
    bcAssert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}orders WHERE id IN (%d,%d) AND status = 'paid'", ...$orderIds)) === 2, 'Orders no quedaron paid.');
    $replay = $processor->process($reconciliationId, 'business_' . str_repeat('d', 32));
    bcAssert($replay->status === BusinessCompletionResult::ALREADY_COMPLETED && $replay->paymentId === $result->paymentId, 'Replay no fue idempotente.');
    bcAssert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}payments WHERE reconciliation_id = %d", $reconciliationId)) === 1, 'Replay duplico Payment.');
    bcAssert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}payment_orders WHERE payment_id = %d", $result->paymentId)) === 2, 'Replay duplico relaciones.');
    $stored = json_encode([$payment, $result, $replay], JSON_THROW_ON_ERROR);
    bcAssert(! str_contains($stored, $financial->tokenHash()), 'Se expuso el token hash como referencia de negocio.');
    echo "OK: business completion materializa Payment y multiples Orders de forma idempotente.\n";
} finally {
    if (isset($created['payment'])) { $wpdb->delete($prefix . 'payment_orders', ['payment_id' => $created['payment']]); }
    if (isset($created['reconciliation'])) {
        $completionId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}business_completions WHERE reconciliation_id = %d", $created['reconciliation']));
        if ($completionId > 0) { $wpdb->delete($prefix . 'business_completion_orders', ['business_completion_id' => $completionId]); }
        $wpdb->delete($prefix . 'business_completions', ['reconciliation_id' => $created['reconciliation']]);
    }
    if (isset($created['session'])) { $wpdb->delete($prefix . 'payment_sessions', ['id' => $created['session']]); }
    if (isset($created['payment'])) { $wpdb->delete($prefix . 'payments', ['id' => $created['payment']]); }
    if (isset($created['checkout'])) { $wpdb->delete($prefix . 'checkout_orders', ['checkout_id' => $created['checkout']]); }
    foreach ($created['orders'] ?? [] as $id) { $wpdb->delete($prefix . 'orders', ['id' => $id]); }
    if (isset($created['reconciliation'])) { $wpdb->delete($prefix . 'payment_reconciliations', ['id' => $created['reconciliation']]); }
    if (isset($created['return'])) { $wpdb->delete($prefix . 'webpay_returns', ['id' => $created['return']]); }
    if (isset($created['origin'])) { $wpdb->delete($prefix . 'payment_origin_contexts', ['id' => $created['origin']]); }
    if (isset($created['checkout'])) { $wpdb->delete($prefix . 'checkouts', ['id' => $created['checkout']]); }
}
