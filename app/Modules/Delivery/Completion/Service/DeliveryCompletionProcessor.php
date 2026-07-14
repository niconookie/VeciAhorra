<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Delivery\Completion\Service;

use Throwable;
use VeciAhorra\Modules\Delivery\Completion\DTO\DeliveryCompletionResult;
use VeciAhorra\Modules\Delivery\Completion\Exception\DeliveryCompletionFailure;
use VeciAhorra\Modules\Delivery\Completion\Repository\DeliveryCompletionRepository;
use VeciAhorra\Modules\Delivery\Models\Delivery;
use VeciAhorra\Modules\Delivery\Repository\DeliveryRepository;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;

final class DeliveryCompletionProcessor
{
    public function __construct(
        private readonly DeliveryCompletionRepository $completions = new DeliveryCompletionRepository(),
        private readonly DeliveryRepository $deliveries = new DeliveryRepository(),
        private readonly OrderRepository $orders = new OrderRepository()
    ) {
    }

    public function process(int $businessCompletionId, string $owner, int $leaseSeconds = 600): DeliveryCompletionResult
    {
        if ($businessCompletionId <= 0) { throw new \InvalidArgumentException('business_completion_id no valido.'); }
        try {
            $completion = $this->completions->ensure($businessCompletionId);
            if (($completion['completion_status'] ?? null) === 'completed') {
                return $this->terminalResult($completion, DeliveryCompletionResult::ALREADY_COMPLETED);
            }
            if (($completion['completion_status'] ?? null) === 'not_required') {
                return $this->terminalResult($completion, DeliveryCompletionResult::NOT_REQUIRED);
            }
            if (in_array($completion['completion_status'] ?? null, ['permanent_failure', 'manual_review'], true)) {
                return new DeliveryCompletionResult((string) $completion['completion_status'], (string) $completion['last_result_code'], $businessCompletionId, (int) $completion['id']);
            }
            $claim = $this->completions->acquire((int) $completion['id'], $owner, $leaseSeconds);
            if ($claim === null) {
                return new DeliveryCompletionResult(DeliveryCompletionResult::RETRYABLE_FAILURE, 'lease_unavailable', $businessCompletionId, (int) $completion['id']);
            }
            return $this->materialize($businessCompletionId, $claim, $owner);
        } catch (Throwable $e) {
            error_log('[VeciAhorra] DeliveryCompletion fallo: ' . get_class($e));
            return new DeliveryCompletionResult(DeliveryCompletionResult::RETRYABLE_FAILURE, 'unexpected_failure', $businessCompletionId);
        }
    }

    /** @return 'renewed'|'expired'|'lost' */
    public function heartbeat(int $deliveryCompletionId, string $owner, int $version, int $leaseSeconds = 600): string
    {
        return $this->completions->renew($deliveryCompletionId, $owner, $version, $leaseSeconds);
    }

    private function materialize(int $businessCompletionId, array $claim, string $owner): DeliveryCompletionResult
    {
        $id = (int) $claim['id']; $version = (int) $claim['lease_version'];
        try {
            $outcome = $this->completions->transaction(function () use ($businessCompletionId, $id, $version, $owner): array {
                if ($this->completions->lock($id, $owner, $version) === null) {
                    throw new DeliveryCompletionFailure('lease_lost', DeliveryCompletionResult::LEASE_LOST);
                }
                $business = $this->completions->lockBusinessCompletion($businessCompletionId);
                if ($business === null || ($business['status'] ?? null) !== 'completed' || empty($business['completed_at'])) {
                    throw new DeliveryCompletionFailure('business_completion_not_completed', DeliveryCompletionResult::PERMANENT_FAILURE);
                }
                $method = $business['fulfillment_method'] ?? null;
                if (! in_array($method, ['pickup', 'delivery'], true)) {
                    throw new DeliveryCompletionFailure('fulfillment_snapshot_invalid', DeliveryCompletionResult::MANUAL_REVIEW);
                }
                $orderIds = $this->completions->snapshotOrderIds($businessCompletionId);
                if ($orderIds === [] || $orderIds !== array_values(array_unique($orderIds))) {
                    throw new DeliveryCompletionFailure('order_snapshot_invalid', DeliveryCompletionResult::MANUAL_REVIEW);
                }
                if ($method === 'pickup') {
                    if (! $this->completions->close($id, $owner, $version, 'not_required', 'pickup')) {
                        throw new DeliveryCompletionFailure('lease_lost', DeliveryCompletionResult::LEASE_LOST);
                    }
                    return ['status' => DeliveryCompletionResult::NOT_REQUIRED, 'delivery_ids' => []];
                }
                $orders = $this->orders->findManyForUpdate($orderIds);
                if (array_map('intval', array_column($orders, 'id')) !== $orderIds) {
                    throw new DeliveryCompletionFailure('snapshot_order_missing', DeliveryCompletionResult::MANUAL_REVIEW);
                }
                $deliveryIds = [];
                foreach ($orders as $order) {
                    if (($order['status'] ?? null) !== 'paid') {
                        throw new DeliveryCompletionFailure('snapshot_order_not_paid', DeliveryCompletionResult::MANUAL_REVIEW);
                    }
                    $orderId = (int) $order['id'];
                    $delivery = $this->deliveries->findByOrderIdForUpdate($orderId);
                    if ($delivery === null) {
                        $deliveryId = $this->deliveries->create([
                            'order_id' => $orderId,
                            'customer_id' => (int) $order['customer_id'],
                            'minimarket_id' => (int) $order['minimarket_id'],
                            'courier_id' => null,
                            'status' => Delivery::STATUS_PENDING,
                            'created_at' => current_time('mysql', true),
                            'updated_at' => current_time('mysql', true),
                        ]);
                        $delivery = $this->deliveries->find($deliveryId);
                    }
                    if ($delivery === null
                        || (int) $delivery['order_id'] !== $orderId
                        || (int) $delivery['customer_id'] !== (int) $order['customer_id']
                        || (int) $delivery['minimarket_id'] !== (int) $order['minimarket_id']
                        || $delivery['courier_id'] !== null
                        || ($delivery['status'] ?? null) !== Delivery::STATUS_PENDING
                    ) {
                        throw new DeliveryCompletionFailure('delivery_identity_conflict', DeliveryCompletionResult::MANUAL_REVIEW);
                    }
                    $deliveryIds[] = (int) $delivery['id'];
                }
                $stored = $this->deliveries->findByOrderIds($orderIds);
                if (count($stored) !== count($orderIds) || array_map('intval', array_column($stored, 'order_id')) !== $orderIds) {
                    throw new DeliveryCompletionFailure('delivery_verification_failed', DeliveryCompletionResult::RETRYABLE_FAILURE);
                }
                if (! $this->completions->close($id, $owner, $version, 'completed', 'deliveries_materialized')) {
                    throw new DeliveryCompletionFailure('lease_lost', DeliveryCompletionResult::LEASE_LOST);
                }
                return ['status' => DeliveryCompletionResult::COMPLETED, 'delivery_ids' => $deliveryIds];
            });
            return new DeliveryCompletionResult($outcome['status'], (string) ($outcome['status'] === DeliveryCompletionResult::NOT_REQUIRED ? 'pickup' : 'deliveries_materialized'), $businessCompletionId, $id, $outcome['delivery_ids']);
        } catch (DeliveryCompletionFailure $failure) {
            $dbStatus = match ($failure->outcome) {
                DeliveryCompletionResult::PERMANENT_FAILURE => 'permanent_failure',
                DeliveryCompletionResult::MANUAL_REVIEW => 'manual_review',
                default => 'retryable',
            };
            try { $this->completions->close($id, $owner, $version, $dbStatus, $failure->reason); } catch (Throwable) {}
            return new DeliveryCompletionResult($failure->outcome, $failure->reason, $businessCompletionId, $id);
        } catch (Throwable $e) {
            try { $this->completions->close($id, $owner, $version, 'retryable', 'unexpected_failure'); } catch (Throwable) {}
            error_log('[VeciAhorra] DeliveryCompletion transaccional fallo: ' . get_class($e));
            return new DeliveryCompletionResult(DeliveryCompletionResult::RETRYABLE_FAILURE, 'unexpected_failure', $businessCompletionId, $id);
        }
    }

    private function terminalResult(array $completion, string $status): DeliveryCompletionResult
    {
        $businessId = (int) $completion['business_completion_id'];
        $orderIds = $this->completions->snapshotOrderIds($businessId);
        $deliveries = $status === DeliveryCompletionResult::NOT_REQUIRED ? [] : $this->deliveries->findByOrderIds($orderIds);
        return new DeliveryCompletionResult($status, (string) $completion['last_result_code'], $businessId, (int) $completion['id'], array_map('intval', array_column($deliveries, 'id')));
    }
}
