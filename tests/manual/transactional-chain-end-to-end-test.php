<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Delivery\Completion\DTO\DeliveryCompletionResult;
use VeciAhorra\Modules\Delivery\Completion\Service\DeliveryCompletionProcessor;
use VeciAhorra\Modules\Fulfillment\Completion\DTO\FulfillmentCompletionResult;
use VeciAhorra\Modules\Fulfillment\Completion\Service\FulfillmentCompletionProcessor;
use VeciAhorra\Modules\Payments\BusinessCompletion\DTO\BusinessCompletionResult;
use VeciAhorra\Modules\Payments\BusinessCompletion\Service\BusinessCompletionProcessor;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\CreatePaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\FinancialFingerprintComponents;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\PaymentReconciliationProcessingResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ValidatedFinancialResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\VeciAhorraPaymentCompletionOutcome;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationClaimRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\ValidatedFinancialResultRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Service\PaymentReconciliationProcessor;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function e2eAssert(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

global $wpdb;
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$created = [];
$paymentCompleteCalls = 0;
$paymentObserver = static function () use (&$paymentCompleteCalls): void { $paymentCompleteCalls++; };
add_action('woocommerce_pre_payment_complete', $paymentObserver, 10, 0);

/** @return array<string,mixed> */
function e2eFixture(string $method, array $amounts): array
{
    global $wpdb, $prefix, $created;
    $nonce = bin2hex(random_bytes(10));
    $now = current_time('mysql', true);
    $total = array_sum($amounts);
    $checkoutPublic = 'chk_' . substr(hash('sha256', 'checkout-' . $nonce), 0, 43);
    $sessionPublic = 'ps_' . substr(hash('sha256', 'session-' . $nonce), 0, 43);
    $wpdb->insert($prefix . 'checkouts', [
        'public_id' => $checkoutPublic, 'owner_type' => 'user', 'user_id' => 970001,
        'session_id' => null, 'status' => 'payment_started', 'fulfillment_method' => $method,
        'currency' => 'CLP', 'total_amount' => $total . '.00', 'created_at' => $now,
        'updated_at' => $now, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $checkoutId = (int) $wpdb->insert_id;
    $orderIds = [];
    foreach ($amounts as $index => $amount) {
        $wpdb->insert($prefix . 'orders', [
            'customer_id' => 970001, 'minimarket_id' => 971000 + $index,
            'total' => $amount . '.00', 'status' => 'reserved',
            'reservation_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $orderId = (int) $wpdb->insert_id;
        $orderIds[] = $orderId;
        $wpdb->insert($prefix . 'checkout_orders', [
            'checkout_id' => $checkoutId, 'order_id' => $orderId, 'created_at' => $now,
        ]);
    }
    sort($orderIds, SORT_NUMERIC);
    $wpdb->insert($prefix . 'payment_sessions', [
        'public_id' => $sessionPublic, 'checkout_id' => $checkoutId, 'payment_id' => null,
        'idempotency_key' => hash('sha256', 'session-key-' . $nonce),
        'request_fingerprint' => hash('sha256', 'session-request-' . $nonce), 'status' => 'ready',
        'provider' => 'webpay_plus', 'provider_session_id' => hash('sha256', 'provider-' . $nonce),
        'redirect_url' => null, 'currency' => 'CLP', 'amount' => $total . '.00', 'metadata' => null,
        'created_at' => $now, 'updated_at' => $now, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $sessionId = (int) $wpdb->insert_id;
    $merchant = hash('sha256', 'merchant-' . $nonce);
    $buyOrder = 'VA' . strtoupper(substr(hash('sha256', 'buy-' . $nonce), 0, 24));
    $financialSession = 'VA-' . strtoupper(substr(hash('sha256', 'financial-' . $nonce), 0, 58));
    $tokenHash = hash('sha256', 'token-' . $nonce);
    $origin = new DurablePaymentOrigin(
        'poc_' . substr(hash('sha256', 'origin-' . $nonce), 0, 40), 'veciahorra:checkout',
        DurablePaymentOrigin::ORIGIN_VECIAHORRA, $checkoutPublic, 'webpay_plus', $sessionPublic,
        $total, 'integration', $merchant, $buyOrder, $financialSession, $tokenHash, 1,
        $now, $now, gmdate('Y-m-d H:i:s', time() + 3600)
    );
    $components = new FinancialFingerprintComponents(
        'integration', $merchant, 'AUTHORIZED', 0, $total, $buyOrder, $financialSession,
        '2026-07-14T12:00:00Z', hash('sha256', 'authorization-' . $nonce), 'VD', 0, '0714'
    );
    $financial = new ValidatedFinancialResult(
        'wpr_' . substr(hash('sha256', 'return-' . $nonce), 0, 40), 'approved', 'commit',
        $tokenHash, 'sha256:' . substr(hash('sha256', 'safe-' . $nonce), 0, 16),
        $components, $now, $now
    );
    $origins = new PaymentOriginContextRepository();
    $returns = new ValidatedFinancialResultRepository();
    $originId = $origins->create($origin);
    $returnId = $returns->create($financial);
    $reconciliationId = (new PaymentReconciliationRepository($origins, $returns))->create(
        new CreatePaymentReconciliation(
            'pr_' . substr(hash('sha256', 'reconciliation-' . $nonce), 0, 40),
            $returnId, $originId, $financial, $origin, PaymentReconciliation::STATUS_PENDING,
            null, 0, null, null, $now, null, null, $now
        )
    );
    $fixture = compact(
        'checkoutId', 'sessionId', 'orderIds', 'originId', 'returnId', 'reconciliationId',
        'method', 'financial'
    );
    $created[] = $fixture;
    return $fixture;
}

/** @return array<string,mixed> */
function e2eRun(array $fixture, string $worker): array
{
    global $wpdb, $prefix;
    $claims = new PaymentReconciliationClaimRepository();
    $lease = $claims->acquireLease(
        $fixture['reconciliationId'], 'worker_' . str_repeat($worker, 32), 60
    )->lease();
    e2eAssert($lease !== null, 'PaymentReconciliation no adquirio lease.');
    $reconciliation = (new PaymentReconciliationProcessor())->process($lease);
    e2eAssert($reconciliation->status() === PaymentReconciliationProcessingResult::PROCESSED
        && $reconciliation->completionOutcome()?->resultCode() === VeciAhorraPaymentCompletionOutcome::RESULT,
        'PaymentReconciliation interno no completo por la cadena real.');
    $business = (new BusinessCompletionProcessor())->process(
        $fixture['reconciliationId'], 'business_' . str_repeat($worker, 32), 30
    );
    e2eAssert($business->status === BusinessCompletionResult::COMPLETED, 'BusinessCompletion no completo.');
    $delivery = (new DeliveryCompletionProcessor())->process(
        (int) $business->completionId, 'worker_' . str_repeat($worker, 32), 30
    );
    $expectedDelivery = $fixture['method'] === 'pickup'
        ? DeliveryCompletionResult::NOT_REQUIRED : DeliveryCompletionResult::COMPLETED;
    e2eAssert($delivery->status === $expectedDelivery, 'DeliveryCompletion no alcanzo estado esperado.');
    $fulfillment = (new FulfillmentCompletionProcessor())->process(
        (int) $business->completionId, 'worker_' . str_repeat($worker, 32), 30
    );
    e2eAssert($fulfillment->status === FulfillmentCompletionResult::COMPLETED, 'FulfillmentCompletion no completo.');
    $businessRow = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$prefix}business_completions WHERE id = %d", $business->completionId
    ), ARRAY_A);
    return compact('reconciliation', 'business', 'delivery', 'fulfillment', 'businessRow');
}

function e2eAssertDurable(array $fixture, array $result): void
{
    global $wpdb, $prefix;
    $business = $result['businessRow'];
    $businessId = (int) $business['id'];
    $reconciliation = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$prefix}payment_reconciliations WHERE id = %d", $fixture['reconciliationId']
    ), ARRAY_A);
    $delivery = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$prefix}delivery_completions WHERE business_completion_id = %d", $businessId
    ), ARRAY_A);
    $fulfillment = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$prefix}fulfillment_completions WHERE business_completion_id = %d", $businessId
    ), ARRAY_A);
    $snapshot = array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT order_id FROM {$prefix}business_completion_orders WHERE business_completion_id = %d ORDER BY order_id",
        $businessId
    )));
    $deliveryOrders = array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT order_id FROM {$prefix}deliveries WHERE order_id IN ("
        . implode(',', array_fill(0, count($fixture['orderIds']), '%d')) . ') ORDER BY order_id',
        ...$fixture['orderIds']
    )));
    e2eAssert($reconciliation['reconciliation_status'] === 'completed'
        && $business['status'] === 'completed'
        && $business['fulfillment_method'] === $fixture['method']
        && $delivery['completion_status'] === ($fixture['method'] === 'pickup' ? 'not_required' : 'completed')
        && $fulfillment['completion_status'] === 'completed', 'Cadena durable incompleta.');
    e2eAssert($snapshot === $fixture['orderIds'], 'Snapshot durable cambio.');
    e2eAssert($deliveryOrders === ($fixture['method'] === 'pickup' ? [] : $fixture['orderIds']), 'Conjunto Delivery no es exacto.');
    foreach ([$reconciliation, $business, $delivery, $fulfillment] as $authority) {
        e2eAssert(($authority['lease_owner'] ?? null) === null
            && ($authority['lease_expires_at'] ?? null) === null,
            'Quedo un lease activo.');
        $status = $authority['reconciliation_status'] ?? $authority['status'] ?? $authority['completion_status'] ?? null;
        e2eAssert($status !== 'processing', 'Quedo estado processing.');
    }
}

try {
    $pickup = e2eFixture('pickup', [1000, 2000]);
    $pickupResult = e2eRun($pickup, 'a');
    e2eAssertDurable($pickup, $pickupResult);

    $delivery = e2eFixture('delivery', [1500, 2500, 3000]);
    $deliveryResult = e2eRun($delivery, 'b');
    e2eAssertDurable($delivery, $deliveryResult);

    foreach ([[$pickup, $pickupResult, 'c'], [$delivery, $deliveryResult, 'd']] as [$fixture, $original, $worker]) {
        $businessId = (int) $original['business']->completionId;
        $countsBefore = [
            'reconciliations' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}payment_reconciliations WHERE id = %d", $fixture['reconciliationId'])),
            'business' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}business_completions WHERE reconciliation_id = %d", $fixture['reconciliationId'])),
            'delivery_authority' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}delivery_completions WHERE business_completion_id = %d", $businessId)),
            'fulfillment_authority' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}fulfillment_completions WHERE business_completion_id = %d", $businessId)),
            'deliveries' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}deliveries WHERE order_id IN (" . implode(',', array_fill(0, count($fixture['orderIds']), '%d')) . ')', ...$fixture['orderIds'])),
        ];
        $snapshotBefore = json_encode([$original['businessRow']['fulfillment_method'], $fixture['orderIds']], JSON_THROW_ON_ERROR);
        e2eAssert((new PaymentReconciliationClaimRepository())->acquireLease(
            $fixture['reconciliationId'], 'worker_' . str_repeat($worker, 32), 60
        )->lease() === null, 'Conciliacion terminal fue readquirida.');
        e2eAssert((new BusinessCompletionProcessor())->process(
            $fixture['reconciliationId'], 'business_' . str_repeat($worker, 32), 30
        )->status === BusinessCompletionResult::ALREADY_COMPLETED, 'Replay Business no fue terminal.');
        e2eAssert((new DeliveryCompletionProcessor())->process(
            $businessId, 'worker_' . str_repeat($worker, 32), 30
        )->status === ($fixture['method'] === 'pickup' ? DeliveryCompletionResult::NOT_REQUIRED : DeliveryCompletionResult::ALREADY_COMPLETED),
        'Replay Delivery no fue terminal.');
        e2eAssert((new FulfillmentCompletionProcessor())->process(
            $businessId, 'worker_' . str_repeat($worker, 32), 30
        )->status === FulfillmentCompletionResult::ALREADY_COMPLETED, 'Replay Fulfillment no fue terminal.');
        $countsAfter = [
            'reconciliations' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}payment_reconciliations WHERE id = %d", $fixture['reconciliationId'])),
            'business' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}business_completions WHERE reconciliation_id = %d", $fixture['reconciliationId'])),
            'delivery_authority' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}delivery_completions WHERE business_completion_id = %d", $businessId)),
            'fulfillment_authority' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}fulfillment_completions WHERE business_completion_id = %d", $businessId)),
            'deliveries' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}deliveries WHERE order_id IN (" . implode(',', array_fill(0, count($fixture['orderIds']), '%d')) . ')', ...$fixture['orderIds'])),
        ];
        $freshBusiness = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}business_completions WHERE id = %d", $businessId), ARRAY_A);
        $freshSnapshot = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT order_id FROM {$prefix}business_completion_orders WHERE business_completion_id = %d ORDER BY order_id", $businessId
        )));
        e2eAssert($countsAfter === $countsBefore
            && json_encode([$freshBusiness['fulfillment_method'], $freshSnapshot], JSON_THROW_ON_ERROR) === $snapshotBefore,
            'Replay global altero autoridades, efectos o snapshot.');
    }

    e2eAssert($paymentCompleteCalls === 0, 'El origen interno invoco payment_complete().');
    echo "PASS transactional-chain-end-to-end-test pickup=completed delivery=completed replay=idempotent\n";
} finally {
    remove_action('woocommerce_pre_payment_complete', $paymentObserver, 10);
    foreach (array_reverse($created) as $fixture) {
        $businessId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}business_completions WHERE reconciliation_id = %d", $fixture['reconciliationId']
        ));
        if ($businessId > 0) {
            $wpdb->delete($prefix . 'fulfillment_completions', ['business_completion_id' => $businessId]);
            $wpdb->delete($prefix . 'delivery_completions', ['business_completion_id' => $businessId]);
            $wpdb->delete($prefix . 'business_completion_orders', ['business_completion_id' => $businessId]);
        }
        foreach ($fixture['orderIds'] as $orderId) { $wpdb->delete($prefix . 'deliveries', ['order_id' => $orderId]); }
        $paymentId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}payments WHERE reconciliation_id = %d", $fixture['reconciliationId']));
        if ($paymentId > 0) { $wpdb->delete($prefix . 'payment_orders', ['payment_id' => $paymentId]); }
        $wpdb->delete($prefix . 'business_completions', ['reconciliation_id' => $fixture['reconciliationId']]);
        $wpdb->delete($prefix . 'payment_sessions', ['id' => $fixture['sessionId']]);
        if ($paymentId > 0) { $wpdb->delete($prefix . 'payments', ['id' => $paymentId]); }
        $wpdb->delete($prefix . 'checkout_orders', ['checkout_id' => $fixture['checkoutId']]);
        foreach ($fixture['orderIds'] as $orderId) { $wpdb->delete($prefix . 'orders', ['id' => $orderId]); }
        $wpdb->delete($prefix . 'payment_reconciliations', ['id' => $fixture['reconciliationId']]);
        $wpdb->delete($prefix . 'webpay_returns', ['id' => $fixture['returnId']]);
        $wpdb->delete($prefix . 'payment_origin_contexts', ['id' => $fixture['originId']]);
        $wpdb->delete($prefix . 'checkouts', ['id' => $fixture['checkoutId']]);
    }
}
