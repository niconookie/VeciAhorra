<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Fulfillment\Orchestration;

use VeciAhorra\Modules\Delivery\Completion\DTO\DeliveryCompletionResult;
use VeciAhorra\Modules\Delivery\Completion\Repository\DeliveryCompletionRepository;
use VeciAhorra\Modules\Delivery\Completion\Service\DeliveryCompletionProcessor;
use VeciAhorra\Modules\Fulfillment\Completion\DTO\FulfillmentCompletionResult;
use VeciAhorra\Modules\Fulfillment\Completion\Repository\FulfillmentCompletionRepository;
use VeciAhorra\Modules\Fulfillment\Completion\Service\FulfillmentCompletionProcessor;
use VeciAhorra\Modules\Payments\BusinessCompletion\DTO\BusinessCompletionResult;
use VeciAhorra\Modules\Payments\BusinessCompletion\Repository\BusinessCompletionRepository;
use VeciAhorra\Modules\Payments\BusinessCompletion\Service\BusinessCompletionProcessor;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\LeaseAcquireResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\PaymentReconciliationProcessingResult;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationClaimRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Service\PaymentReconciliationProcessor;

final class DurableCompletionWorkers
{
    public function __construct(
        private readonly DurableCompletionScheduler $scheduler = new DurableCompletionScheduler(),
        private readonly CompletionBranchPolicy $branches = new CompletionBranchPolicy()
    ) {}

    public function reconciliation(int $id): void
    {
        $claims = new PaymentReconciliationClaimRepository();
        $claim = $claims->acquireLease($id, PaymentReconciliationClaimRepository::ownerId());
        if ($claim->acquired()) {
            (new PaymentReconciliationProcessor())->process($claim->lease());
        }
        $row = (new PaymentReconciliationRepository())->find($id);
        if ($row?->status() === PaymentReconciliation::STATUS_COMPLETED
            && $this->branches->nextAfterReconciliation($row)
                === CompletionBranchPolicy::BUSINESS_COMPLETION) {
            $this->scheduler->business($id);
        } elseif ($claim->status() === LeaseAcquireResult::BUSY
            || in_array($row?->status(), [PaymentReconciliation::STATUS_PENDING, PaymentReconciliation::STATUS_RETRYABLE, PaymentReconciliation::STATUS_PROCESSING], true)) {
            $this->scheduler->retry(DurableCompletionScheduler::RECONCILIATION, $id, $row?->attemptCount() ?? 0);
        }
    }

    public function business(int $reconciliationId): void
    {
        $result = (new BusinessCompletionProcessor())->process($reconciliationId, 'business_' . bin2hex(random_bytes(16)));
        $row = (new BusinessCompletionRepository())->findByReconciliation($reconciliationId);
        if (($row['status'] ?? null) === 'completed') {
            $this->scheduler->delivery((int) $row['id']);
        } elseif (in_array($result->status, [BusinessCompletionResult::RETRYABLE, BusinessCompletionResult::LEASE_LOST], true)) {
            $this->scheduler->retry(DurableCompletionScheduler::BUSINESS, $reconciliationId, (int) ($row['attempt_count'] ?? 0));
        }
    }

    public function delivery(int $businessId): void
    {
        $result = (new DeliveryCompletionProcessor())->process($businessId, 'worker_' . bin2hex(random_bytes(16)));
        $row = (new DeliveryCompletionRepository())->findByBusinessCompletion($businessId);
        if (in_array($row['completion_status'] ?? null, ['completed', 'not_required'], true)) {
            $this->scheduler->fulfillment($businessId);
        } elseif (in_array($result->status, [DeliveryCompletionResult::RETRYABLE_FAILURE, DeliveryCompletionResult::LEASE_LOST], true)) {
            $this->scheduler->retry(DurableCompletionScheduler::DELIVERY, $businessId, (int) ($row['attempt_count'] ?? 0));
        }
    }

    public function fulfillment(int $businessId): void
    {
        $result = (new FulfillmentCompletionProcessor())->process($businessId, FulfillmentCompletionRepository::ownerId());
        $row = (new FulfillmentCompletionRepository())->findByBusinessCompletion($businessId);
        if (in_array($result->status, [FulfillmentCompletionResult::RETRYABLE, FulfillmentCompletionResult::LEASE_LOST], true)) {
            $this->scheduler->retry(DurableCompletionScheduler::FULFILLMENT, $businessId, (int) ($row['attempt_count'] ?? 0));
        }
    }
}
