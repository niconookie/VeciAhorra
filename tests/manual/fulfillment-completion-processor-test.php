<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Migrations\CreateFulfillmentCompletionsTable;
use VeciAhorra\Modules\Fulfillment\Completion\DTO\FulfillmentCompletionResult;
use VeciAhorra\Modules\Fulfillment\Completion\Repository\FulfillmentCompletionRepository;
use VeciAhorra\Modules\Fulfillment\Completion\Service\FulfillmentCompletionProcessor;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function fcAssert(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

global $wpdb;
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
(new CreateFulfillmentCompletionsTable())->up();
(new CreateFulfillmentCompletionsTable())->up();
$processor = new FulfillmentCompletionProcessor();
$repository = new FulfillmentCompletionRepository();
$cleanup = ['business' => [], 'orders' => []];

/** @return array{business_id:int,order_ids:list<int>} */
function fcFixture(string $method, string $deliveryStatus, int $count, bool $materializeDeliveries): array
{
    global $wpdb, $prefix, $cleanup;
    $now = current_time('mysql', true);
    $nonce = bin2hex(random_bytes(8));
    $wpdb->insert($prefix . 'business_completions', [
        'reconciliation_id' => random_int(100000000, 900000000),
        'idempotency_key' => hash('sha256', 'fc-' . $nonce),
        'status' => 'completed', 'fulfillment_method' => $method,
        'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $businessId = (int) $wpdb->insert_id;
    $cleanup['business'][] = $businessId;
    $orderIds = [];
    for ($index = 0; $index < $count; $index++) {
        $orderId = random_int(1000000000, 2000000000);
        $orderIds[] = $orderId;
        $cleanup['orders'][] = $orderId;
        $wpdb->insert($prefix . 'business_completion_orders', [
            'business_completion_id' => $businessId,
            'order_id' => $orderId,
            'created_at' => $now,
        ]);
        if ($materializeDeliveries) {
            $wpdb->insert($prefix . 'deliveries', [
                'order_id' => $orderId,
                'customer_id' => 910000 + $index,
                'minimarket_id' => 920000 + $index,
                'courier_id' => null,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
    $wpdb->insert($prefix . 'delivery_completions', [
        'business_completion_id' => $businessId,
        'idempotency_key' => hash('sha256', 'fc-dc-' . $nonce),
        'completion_status' => $deliveryStatus,
        'completed_at' => in_array($deliveryStatus, ['completed', 'not_required'], true) ? $now : null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    sort($orderIds, SORT_NUMERIC);
    return ['business_id' => $businessId, 'order_ids' => $orderIds];
}

/** @return array<string,mixed> */
function fcDurable(int $businessId): array
{
    global $wpdb, $prefix;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$prefix}fulfillment_completions WHERE business_completion_id = %d",
        $businessId
    ), ARRAY_A);
}

try {
    $pickup = fcFixture('pickup', 'not_required', 2, false);
    $pickupResult = $processor->process($pickup['business_id'], 'worker_' . str_repeat('1', 32), 30);
    fcAssert($pickupResult->status === FulfillmentCompletionResult::COMPLETED, 'Pickup no completo fulfillment.');
    fcAssert(fcDurable($pickup['business_id'])['completion_status'] === 'completed', 'Pickup no quedo durable completed.');
    fcAssert((int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}deliveries WHERE order_id IN (%d,%d)", ...$pickup['order_ids']
    )) === 0, 'Pickup materializo Delivery.');

    $delivery = fcFixture('delivery', 'completed', 2, true);
    $before = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}deliveries WHERE order_id IN (%d,%d)", ...$delivery['order_ids']
    ));
    $first = $processor->process($delivery['business_id'], 'worker_' . str_repeat('2', 32), 30);
    $replay = $processor->process($delivery['business_id'], 'worker_' . str_repeat('3', 32), 30);
    $after = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}deliveries WHERE order_id IN (%d,%d)", ...$delivery['order_ids']
    ));
    fcAssert($first->status === FulfillmentCompletionResult::COMPLETED, 'Delivery no completo fulfillment.');
    fcAssert($replay->status === FulfillmentCompletionResult::ALREADY_COMPLETED, 'Replay no fue terminal.');
    fcAssert($before === 2 && $after === 2, 'Replay produjo efectos duplicados.');

    $leaseFixture = fcFixture('pickup', 'not_required', 1, false);
    $leaseRow = $repository->ensure($leaseFixture['business_id']);
    $ownerA = 'worker_' . str_repeat('4', 32);
    $ownerB = 'worker_' . str_repeat('5', 32);
    $leaseA = $repository->acquire((int) $leaseRow['id'], $ownerA, 30);
    fcAssert($leaseA !== null, 'No se adquirio lease.');
    fcAssert($repository->acquire((int) $leaseRow['id'], $ownerB, 30) === null, 'Worker concurrente adquirio lease.');
    $duringContention = fcDurable($leaseFixture['business_id']);
    fcAssert($duringContention['completion_status'] === 'processing'
        && $duringContention['lease_owner'] === $ownerA
        && (int) $duringContention['lease_version'] === (int) $leaseA['lease_version'],
        'La contencion altero el lease durable.');
    fcAssert($repository->renew((int) $leaseRow['id'], $ownerA, (int) $leaseA['lease_version'], 30) === 'renewed', 'Heartbeat no renovo.');
    fcAssert(! $repository->close((int) $leaseRow['id'], $ownerB, (int) $leaseA['lease_version'], 'completed', 'wrong_owner'), 'CAS acepto owner incorrecto.');
    $wpdb->update($prefix . 'fulfillment_completions', ['lease_expires_at' => '2000-01-01 00:00:00'], ['id' => (int) $leaseRow['id']]);
    fcAssert($repository->renew((int) $leaseRow['id'], $ownerA, (int) $leaseA['lease_version'], 30) === 'expired', 'Lease expirado fue renovado.');
    $leaseB = $repository->acquire((int) $leaseRow['id'], $ownerB, 30);
    fcAssert($leaseB !== null && (int) $leaseB['lease_version'] === (int) $leaseA['lease_version'] + 1, 'Recuperacion no incremento version.');
    fcAssert(! $repository->close((int) $leaseRow['id'], $ownerA, (int) $leaseA['lease_version'], 'completed', 'stale_worker'), 'CAS permitio ABA.');
    $afterStaleCas = fcDurable($leaseFixture['business_id']);
    fcAssert($afterStaleCas['completion_status'] === 'processing'
        && $afterStaleCas['lease_owner'] === $ownerB
        && (int) $afterStaleCas['lease_version'] === (int) $leaseB['lease_version'],
        'El CAS obsoleto altero estado durable.');
    fcAssert($repository->close((int) $leaseRow['id'], $ownerB, (int) $leaseB['lease_version'], 'retryable', 'crash_recovered'), 'No se libero lease recuperado.');
    $recovered = $processor->process($leaseFixture['business_id'], 'worker_' . str_repeat('6', 32), 30);
    fcAssert($recovered->status === FulfillmentCompletionResult::COMPLETED, 'Reinicio no recupero processing expirado.');
    $afterRecovery = fcDurable($leaseFixture['business_id']);
    fcAssert($afterRecovery['completion_status'] === 'completed'
        && $afterRecovery['lease_owner'] === null
        && (int) $afterRecovery['lease_version'] > (int) $leaseB['lease_version'],
        'La recuperacion no cerro durablemente ni libero el lease.');

    $retry = fcFixture('delivery', 'pending', 1, false);
    $retryResult = $processor->process($retry['business_id'], 'worker_' . str_repeat('7', 32), 30);
    fcAssert($retryResult->status === FulfillmentCompletionResult::RETRYABLE, 'Etapa previa pendiente no fue retryable.');
    fcAssert(fcDurable($retry['business_id'])['completion_status'] === 'retryable', 'Retry no quedo durable.');

    $manual = fcFixture('delivery', 'not_required', 1, false);
    $manualResult = $processor->process($manual['business_id'], 'worker_' . str_repeat('8', 32), 30);
    fcAssert($manualResult->status === FulfillmentCompletionResult::MANUAL_REVIEW, 'Contradiccion no fue manual_review.');
    fcAssert(fcDurable($manual['business_id'])['completion_status'] === 'manual_review', 'Manual review no quedo durable.');
    fcAssert((int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}deliveries WHERE order_id = %d", $manual['order_ids'][0]
    )) === 0, 'Rollback dejo efecto parcial.');

    $missingBusinessId = random_int(1000000000, 2000000000);
    $permanent = $processor->process($missingBusinessId, 'worker_' . str_repeat('9', 32), 30);
    fcAssert($permanent->status === FulfillmentCompletionResult::PERMANENT_FAILURE, 'Autoridad ausente no fue permanent_failure.');
    fcAssert(fcDurable($missingBusinessId)['completion_status'] === 'permanent_failure', 'Permanent failure no quedo durable.');
    $cleanup['fulfillment_only'][] = $missingBusinessId;

    echo "PASS fulfillment-completion-processor-test\n";
} finally {
    foreach ($cleanup['business'] as $businessId) {
        $wpdb->delete($prefix . 'fulfillment_completions', ['business_completion_id' => $businessId]);
        $wpdb->delete($prefix . 'delivery_completions', ['business_completion_id' => $businessId]);
        $wpdb->delete($prefix . 'business_completion_orders', ['business_completion_id' => $businessId]);
        $wpdb->delete($prefix . 'business_completions', ['id' => $businessId]);
    }
    foreach ($cleanup['fulfillment_only'] ?? [] as $businessId) {
        $wpdb->delete($prefix . 'fulfillment_completions', ['business_completion_id' => $businessId]);
    }
    foreach ($cleanup['orders'] as $orderId) {
        $wpdb->delete($prefix . 'deliveries', ['order_id' => $orderId]);
    }
}
