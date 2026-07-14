<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Fulfillment\Completion\Service;

use Throwable;
use VeciAhorra\Modules\Fulfillment\Completion\DTO\FulfillmentCompletionResult;
use VeciAhorra\Modules\Fulfillment\Completion\Exception\FulfillmentCompletionFailure;
use VeciAhorra\Modules\Fulfillment\Completion\Repository\FulfillmentCompletionRepository;

final class FulfillmentCompletionProcessor
{
    public function __construct(
        private readonly FulfillmentCompletionRepository $completions = new FulfillmentCompletionRepository()
    ) {
    }

    public function process(
        int $businessCompletionId,
        string $owner,
        int $leaseSeconds = FulfillmentCompletionRepository::DEFAULT_LEASE_SECONDS
    ): FulfillmentCompletionResult {
        if ($businessCompletionId <= 0) {
            throw new \InvalidArgumentException('business_completion_id no valido.');
        }
        try {
            $completion = $this->completions->ensure($businessCompletionId);
            $status = (string) ($completion['completion_status'] ?? '');
            if ($status === 'completed') {
                return $this->result($completion, FulfillmentCompletionResult::ALREADY_COMPLETED);
            }
            if (in_array($status, ['permanent_failure', 'manual_review'], true)) {
                return $this->result($completion, $status);
            }
            $claim = $this->completions->acquire((int) $completion['id'], $owner, $leaseSeconds);
            if ($claim === null) {
                return new FulfillmentCompletionResult(
                    FulfillmentCompletionResult::RETRYABLE,
                    'lease_unavailable',
                    $businessCompletionId,
                    (int) $completion['id']
                );
            }
            return $this->execute($businessCompletionId, $claim, $owner);
        } catch (Throwable $error) {
            error_log('[VeciAhorra] FulfillmentCompletion fallo: ' . get_class($error));
            return new FulfillmentCompletionResult(
                FulfillmentCompletionResult::RETRYABLE,
                'unexpected_failure',
                $businessCompletionId
            );
        }
    }

    /** @return 'renewed'|'expired'|'lost' */
    public function heartbeat(int $id, string $owner, int $version, int $leaseSeconds = 600): string
    {
        return $this->completions->renew($id, $owner, $version, $leaseSeconds);
    }

    private function execute(int $businessCompletionId, array $claim, string $owner): FulfillmentCompletionResult
    {
        $id = (int) $claim['id'];
        $version = (int) $claim['lease_version'];
        try {
            $reason = $this->completions->transaction(function () use (
                $businessCompletionId,
                $id,
                $version,
                $owner
            ): string {
                if ($this->completions->lock($id, $owner, $version) === null) {
                    throw new FulfillmentCompletionFailure('lease_lost', FulfillmentCompletionResult::LEASE_LOST);
                }
                $business = $this->completions->lockBusinessCompletion($businessCompletionId);
                if ($business === null || ($business['status'] ?? null) !== 'completed' || empty($business['completed_at'])) {
                    throw new FulfillmentCompletionFailure(
                        'business_completion_not_completed',
                        FulfillmentCompletionResult::PERMANENT_FAILURE
                    );
                }
                $method = $business['fulfillment_method'] ?? null;
                if (! in_array($method, ['pickup', 'delivery'], true)) {
                    throw new FulfillmentCompletionFailure(
                        'fulfillment_snapshot_invalid',
                        FulfillmentCompletionResult::MANUAL_REVIEW
                    );
                }
                $orderIds = $this->completions->lockSnapshotOrderIds($businessCompletionId);
                if ($orderIds === [] || $orderIds !== array_values(array_unique($orderIds))) {
                    throw new FulfillmentCompletionFailure(
                        'order_snapshot_invalid',
                        FulfillmentCompletionResult::PERMANENT_FAILURE
                    );
                }
                $deliveryCompletion = $this->completions->lockDeliveryCompletion($businessCompletionId);
                if ($deliveryCompletion === null
                    || in_array($deliveryCompletion['completion_status'] ?? null, ['pending', 'processing', 'retryable'], true)
                ) {
                    throw new FulfillmentCompletionFailure(
                        'delivery_completion_not_ready',
                        FulfillmentCompletionResult::RETRYABLE
                    );
                }
                if (in_array($deliveryCompletion['completion_status'] ?? null, ['manual_review', 'permanent_failure'], true)) {
                    throw new FulfillmentCompletionFailure(
                        'delivery_completion_failed',
                        FulfillmentCompletionResult::MANUAL_REVIEW
                    );
                }
                $expectedDeliveryStatus = $method === 'pickup' ? 'not_required' : 'completed';
                if (($deliveryCompletion['completion_status'] ?? null) !== $expectedDeliveryStatus) {
                    throw new FulfillmentCompletionFailure(
                        'delivery_completion_conflict',
                        FulfillmentCompletionResult::MANUAL_REVIEW
                    );
                }
                $deliveryOrderIds = $this->completions->lockDeliveryOrderIds($orderIds);
                if (($method === 'pickup' && $deliveryOrderIds !== [])
                    || ($method === 'delivery' && $deliveryOrderIds !== $orderIds)
                ) {
                    throw new FulfillmentCompletionFailure(
                        'delivery_set_conflict',
                        FulfillmentCompletionResult::MANUAL_REVIEW
                    );
                }
                $reason = $method === 'pickup' ? 'pickup_fulfillment_completed' : 'delivery_fulfillment_completed';
                if (! $this->completions->close($id, $owner, $version, 'completed', $reason)) {
                    throw new FulfillmentCompletionFailure('lease_lost', FulfillmentCompletionResult::LEASE_LOST);
                }
                return $reason;
            });
            return new FulfillmentCompletionResult(
                FulfillmentCompletionResult::COMPLETED,
                $reason,
                $businessCompletionId,
                $id
            );
        } catch (FulfillmentCompletionFailure $failure) {
            $status = match ($failure->outcome) {
                FulfillmentCompletionResult::PERMANENT_FAILURE => 'permanent_failure',
                FulfillmentCompletionResult::MANUAL_REVIEW => 'manual_review',
                default => 'retryable',
            };
            try {
                if (! $this->completions->close($id, $owner, $version, $status, $failure->reason)) {
                    return new FulfillmentCompletionResult(
                        FulfillmentCompletionResult::LEASE_LOST,
                        'lease_lost',
                        $businessCompletionId,
                        $id
                    );
                }
            } catch (Throwable) {
                return new FulfillmentCompletionResult(
                    FulfillmentCompletionResult::LEASE_LOST,
                    'failure_state_not_persisted',
                    $businessCompletionId,
                    $id
                );
            }
            return new FulfillmentCompletionResult($failure->outcome, $failure->reason, $businessCompletionId, $id);
        } catch (Throwable $error) {
            try {
                $closed = $this->completions->close($id, $owner, $version, 'retryable', 'unexpected_failure');
            } catch (Throwable) {
                $closed = false;
            }
            error_log('[VeciAhorra] FulfillmentCompletion transaccional fallo: ' . get_class($error));
            return new FulfillmentCompletionResult(
                $closed ? FulfillmentCompletionResult::RETRYABLE : FulfillmentCompletionResult::LEASE_LOST,
                $closed ? 'unexpected_failure' : 'failure_state_not_persisted',
                $businessCompletionId,
                $id
            );
        }
    }

    private function result(array $completion, string $status): FulfillmentCompletionResult
    {
        return new FulfillmentCompletionResult(
            $status,
            (string) ($completion['last_result_code'] ?? ''),
            (int) $completion['business_completion_id'],
            (int) $completion['id']
        );
    }
}
