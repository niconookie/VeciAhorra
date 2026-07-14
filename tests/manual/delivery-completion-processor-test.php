<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Migrations\CreateDeliveriesTable;
use VeciAhorra\Database\Migrations\CreateDeliveryCompletionsTable;
use VeciAhorra\Database\Migrations\EnsureUniqueDeliveryOrder;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Delivery\Completion\DTO\DeliveryCompletionResult;
use VeciAhorra\Modules\Delivery\Completion\Repository\DeliveryCompletionRepository;
use VeciAhorra\Modules\Delivery\Completion\Service\DeliveryCompletionProcessor;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function dcAssert(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

global $wpdb;
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
(new CreateDeliveriesTable())->up();
(new EnsureUniqueDeliveryOrder())->up();
(new EnsureUniqueDeliveryOrder())->up();
(new CreateDeliveryCompletionsTable())->up();
(new CreateDeliveryCompletionsTable())->up();
$processor = new DeliveryCompletionProcessor();
$repository = new DeliveryCompletionRepository();
$cleanup = [];

/** @return array{business_id:int,order_ids:list<int>} */
function dcFixture(string $method, int $orderCount): array
{
    global $wpdb, $prefix, $cleanup;
    $now = current_time('mysql', true);
    $nonce = bin2hex(random_bytes(8));
    $wpdb->insert($prefix . 'business_completions', [
        'reconciliation_id' => random_int(100000000, 900000000),
        'idempotency_key' => hash('sha256', 'dc-' . $nonce),
        'status' => 'completed', 'fulfillment_method' => $method,
        'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $businessId = (int) $wpdb->insert_id;
    $cleanup['business'][] = $businessId;
    $orderIds = [];
    for ($i = 0; $i < $orderCount; $i++) {
        $wpdb->insert($prefix . 'orders', [
            'customer_id' => 960000 + $businessId,
            'minimarket_id' => 950000 + $i,
            'total' => '1000.00', 'status' => 'paid',
            'reservation_expires_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $orderId = (int) $wpdb->insert_id;
        $orderIds[] = $orderId; $cleanup['orders'][] = $orderId;
        $wpdb->insert($prefix . 'business_completion_orders', [
            'business_completion_id' => $businessId, 'order_id' => $orderId, 'created_at' => $now,
        ]);
    }
    return ['business_id' => $businessId, 'order_ids' => $orderIds];
}

try {
    $pickup = dcFixture('pickup', 2);
    $pickupResult = $processor->process($pickup['business_id'], 'worker_' . str_repeat('1', 32), 30);
    dcAssert($pickupResult->status === DeliveryCompletionResult::NOT_REQUIRED, 'Pickup no termino NOT_REQUIRED.');
    dcAssert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}deliveries WHERE order_id IN (%d,%d)", ...$pickup['order_ids'])) === 0, 'Pickup creo Delivery.');

    $single = dcFixture('delivery', 1);
    $singleResult = $processor->process($single['business_id'], 'worker_' . str_repeat('2', 32), 30);
    dcAssert($singleResult->status === DeliveryCompletionResult::COMPLETED && count($singleResult->deliveryIds) === 1, 'Delivery simple no materializo una entidad.');

    $multiple = dcFixture('delivery', 3);
    $multipleResult = $processor->process($multiple['business_id'], 'worker_' . str_repeat('3', 32), 30);
    dcAssert($multipleResult->status === DeliveryCompletionResult::COMPLETED && count($multipleResult->deliveryIds) === 3, 'Delivery multiple no materializo una por Order.');
    $replay = $processor->process($multiple['business_id'], 'worker_' . str_repeat('4', 32), 30);
    dcAssert($replay->status === DeliveryCompletionResult::ALREADY_COMPLETED && count($replay->deliveryIds) === 3, 'Replay no fue idempotente.');
    dcAssert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}deliveries WHERE order_id IN (%d,%d,%d)", ...$multiple['order_ids'])) === 3, 'Replay duplico Deliveries.');
    (new EnsureUniqueDeliveryOrder())->up();

    $migrationTable = $prefix . 'deliveries_unique_audit_' . bin2hex(random_bytes(4));
    $cleanup['migration_table'] = $migrationTable;
    $wpdb->query("CREATE TABLE `{$migrationTable}` LIKE `{$prefix}deliveries`");
    $wpdb->query("ALTER TABLE `{$migrationTable}` DROP INDEX `deliveries_order_id_unique`");
    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}deliveries WHERE order_id = %d", $multiple['order_ids'][0]), ARRAY_A);
    unset($existing['id']);
    $wpdb->insert($migrationTable, $existing);
    $wpdb->insert($migrationTable, $existing);
    try {
        (new EnsureUniqueDeliveryOrder($migrationTable))->up();
        dcAssert(false, 'La migracion acepto filas historicas duplicadas.');
    } catch (PersistenceException) {
        dcAssert((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$migrationTable}`") === 2, 'La migracion altero filas historicas duplicadas.');
    }

    $leaseFixture = dcFixture('pickup', 1);
    $leaseRow = $repository->ensure($leaseFixture['business_id']);
    $lease = $repository->acquire((int) $leaseRow['id'], 'worker_' . str_repeat('5', 32), 30);
    dcAssert($lease !== null && $repository->renew((int) $leaseRow['id'], 'worker_' . str_repeat('5', 32), (int) $lease['lease_version'], 30) === 'renewed', 'Lease no fue adquirido/renovado.');
    dcAssert($repository->close((int) $leaseRow['id'], 'worker_' . str_repeat('6', 32), (int) $lease['lease_version'], 'retryable', 'wrong_owner') === false, 'CAS acepto otro owner.');
    $wpdb->update($prefix . 'delivery_completions', ['lease_expires_at' => '2000-01-01 00:00:00'], ['id' => (int) $leaseRow['id']]);
    dcAssert($repository->renew((int) $leaseRow['id'], 'worker_' . str_repeat('5', 32), (int) $lease['lease_version'], 30) === 'expired', 'Lease expirado fue renovado.');
    $reclaimed = $repository->acquire((int) $leaseRow['id'], 'worker_' . str_repeat('6', 32), 30);
    dcAssert($reclaimed !== null && (int) $reclaimed['lease_version'] === (int) $lease['lease_version'] + 1, 'Lease expirado no fue reclamado con version anti-ABA.');
    $repository->close((int) $leaseRow['id'], 'worker_' . str_repeat('6', 32), (int) $reclaimed['lease_version'], 'retryable', 'fixture_release');

    $rollback = dcFixture('delivery', 2);
    $secondOrder = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}orders WHERE id = %d", $rollback['order_ids'][1]), ARRAY_A);
    $wpdb->insert($prefix . 'deliveries', [
        'order_id' => $rollback['order_ids'][1], 'customer_id' => (int) $secondOrder['customer_id'] + 1,
        'minimarket_id' => (int) $secondOrder['minimarket_id'], 'courier_id' => null,
        'status' => 'pending', 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true),
    ]);
    $cleanup['deliveries'][] = (int) $wpdb->insert_id;
    $rollbackResult = $processor->process($rollback['business_id'], 'worker_' . str_repeat('7', 32), 30);
    dcAssert($rollbackResult->status === DeliveryCompletionResult::MANUAL_REVIEW, 'Conflicto durable no fue manual_review.');
    dcAssert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}deliveries WHERE order_id = %d", $rollback['order_ids'][0])) === 0, 'El fallo parcial no revirtio la primera Delivery.');

    foreach ($multiple['order_ids'] as $orderId) {
        dcAssert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}deliveries WHERE order_id = %d", $orderId)) === 1, 'La verificacion durable no encontro exactamente una Delivery.');
    }
    echo "PASS delivery-completion-processor-test\n";
} finally {
    if (isset($cleanup['migration_table'])) { $wpdb->query("DROP TABLE IF EXISTS `{$cleanup['migration_table']}`"); }
    foreach ($cleanup['business'] ?? [] as $businessId) {
        $wpdb->delete($prefix . 'delivery_completions', ['business_completion_id' => $businessId]);
        $wpdb->delete($prefix . 'business_completion_orders', ['business_completion_id' => $businessId]);
    }
    foreach ($cleanup['orders'] ?? [] as $orderId) {
        $wpdb->delete($prefix . 'deliveries', ['order_id' => $orderId]);
        $wpdb->delete($prefix . 'orders', ['id' => $orderId]);
    }
    foreach ($cleanup['business'] ?? [] as $businessId) { $wpdb->delete($prefix . 'business_completions', ['id' => $businessId]); }
}
