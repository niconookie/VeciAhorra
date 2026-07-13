<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Service;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentReconciliationTechnicalEvaluatorInterface;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\ReconciliationClockInterface;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\PaymentReconciliationProcessingResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationLease;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\StatusTransitionResult;
use VeciAhorra\Modules\Payments\Reconciliation\Exception\InconsistentReconciliationEvidence;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationClaimRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\ValidatedFinancialResultRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Support\SystemReconciliationClock;

/**
 * Reconciles durable evidence only. A completed reconciliation does not mean
 * that an order was paid or that any business-side effect was performed.
 */
final class PaymentReconciliationProcessor
{
    public function __construct(
        private readonly PaymentReconciliationClaimRepository $claims = new PaymentReconciliationClaimRepository(),
        private readonly PaymentReconciliationRepository $reconciliations = new PaymentReconciliationRepository(),
        private readonly PaymentOriginContextRepository $origins = new PaymentOriginContextRepository(),
        private readonly ValidatedFinancialResultRepository $financialResults = new ValidatedFinancialResultRepository(),
        private readonly PaymentReconciliationTechnicalEvaluatorInterface $evaluator = new PaymentReconciliationTechnicalEvaluator(),
        private readonly ReconciliationClockInterface $clock = new SystemReconciliationClock(),
        private readonly int $heartbeatThresholdSeconds = 30,
        private readonly int $heartbeatLeaseSeconds = PaymentReconciliationClaimRepository::DEFAULT_LEASE_SECONDS
    ) {
        if (
            $heartbeatThresholdSeconds < 0
            || $heartbeatThresholdSeconds > 3600
            || $heartbeatLeaseSeconds < 1
            || $heartbeatLeaseSeconds > 3600
        ) {
            throw new InvalidArgumentException(
                'La configuracion de heartbeat no es valida.'
            );
        }
    }

    public function process(
        ReconciliationLease $lease
    ): PaymentReconciliationProcessingResult {
        $startedAt = $this->clock->now();
        $authority = $this->authorityStatus($lease);

        if ($authority !== null) {
            return new PaymentReconciliationProcessingResult($authority);
        }

        try {
            $references = $this->reconciliations->findReferences(
                $lease->reconciliationId()
            );

            if ($references === null) {
                return new PaymentReconciliationProcessingResult(
                    PaymentReconciliationProcessingResult::NOT_FOUND
                );
            }

            if (
                $references->reconciliationStatus()
                !== PaymentReconciliation::STATUS_PROCESSING
            ) {
                return new PaymentReconciliationProcessingResult(
                    PaymentReconciliationProcessingResult::NOT_PROCESSABLE
                );
            }

            $origin = $this->origins->find($references->originContextId());

            if ($origin === null) {
                return $this->recover(
                    $lease,
                    PaymentReconciliationProcessingResult::ORIGIN_CONTEXT_MISSING,
                    'origin_context_missing'
                );
            }

            $financialResult = $this->financialResults->find(
                $references->webpayReturnId()
            );

            if ($financialResult === null) {
                return $this->recover(
                    $lease,
                    PaymentReconciliationProcessingResult::FINANCIAL_EVIDENCE_MISSING,
                    'financial_evidence_missing'
                );
            }

            try {
                $technicalResult = $this->evaluator->evaluate(
                    $references,
                    $origin,
                    $financialResult
                );
            } catch (InconsistentReconciliationEvidence) {
                return $this->recover(
                    $lease,
                    PaymentReconciliationProcessingResult::INCONSISTENT_EVIDENCE,
                    'inconsistent_evidence'
                );
            }

            $heartbeatPerformed = false;
            $state = $this->claims->findLease($lease->reconciliationId());

            if ($state === null) {
                return new PaymentReconciliationProcessingResult(
                    PaymentReconciliationProcessingResult::NOT_FOUND
                );
            }

            $elapsed = $this->clock->now() - $startedAt;
            $remaining = strtotime((string) $state->expiresAt())
                - strtotime($state->databaseNow());

            if (
                $elapsed >= $this->heartbeatThresholdSeconds
                || $remaining <= $this->heartbeatThresholdSeconds
            ) {
                $renewal = $this->claims->renewLease(
                    $lease->reconciliationId(),
                    $lease->owner(),
                    $lease->version(),
                    $this->heartbeatLeaseSeconds
                );

                if (! $renewal->renewed()) {
                    return new PaymentReconciliationProcessingResult(
                        PaymentReconciliationProcessingResult::HEARTBEAT_REJECTED
                    );
                }

                $heartbeatPerformed = true;
            }

            $authority = $this->authorityStatus($lease, false);

            if ($authority !== null) {
                return new PaymentReconciliationProcessingResult($authority);
            }

            $transition = $this->claims->compareAndSetStatus(
                $lease->reconciliationId(),
                $lease->owner(),
                $lease->version(),
                PaymentReconciliation::STATUS_PROCESSING,
                PaymentReconciliation::STATUS_COMPLETED,
                $technicalResult->resultCode()
            );

            if (! $transition->applied()) {
                return new PaymentReconciliationProcessingResult(
                    $this->casFailureStatus($transition)
                );
            }

            return new PaymentReconciliationProcessingResult(
                PaymentReconciliationProcessingResult::PROCESSED,
                $technicalResult,
                $heartbeatPerformed
            );
        } catch (Throwable) {
            return $this->recover(
                $lease,
                PaymentReconciliationProcessingResult::RECOVERABLE_ERROR,
                'technical_internal_error'
            );
        }
    }

    private function authorityStatus(
        ReconciliationLease $lease,
        bool $requireExactExpiration = true
    ): ?string {
        $state = $this->claims->findLease($lease->reconciliationId());

        if ($state === null) {
            return PaymentReconciliationProcessingResult::NOT_FOUND;
        }

        if ($state->reconciliationStatus() !== PaymentReconciliation::STATUS_PROCESSING) {
            return PaymentReconciliationProcessingResult::NOT_PROCESSABLE;
        }

        if (
            $state->owner() !== $lease->owner()
            || $state->version() !== $lease->version()
            || ! $state->active()
        ) {
            return PaymentReconciliationProcessingResult::AUTHORITY_LOST;
        }

        if ($requireExactExpiration && $state->expiresAt() !== $lease->expiresAt()) {
            return PaymentReconciliationProcessingResult::INVALID_LEASE;
        }

        return null;
    }

    private function recover(
        ReconciliationLease $lease,
        string $processingStatus,
        string $errorCode
    ): PaymentReconciliationProcessingResult {
        try {
            $transition = $this->claims->compareAndSetStatus(
                $lease->reconciliationId(),
                $lease->owner(),
                $lease->version(),
                PaymentReconciliation::STATUS_PROCESSING,
                PaymentReconciliation::STATUS_RETRYABLE,
                null,
                $errorCode
            );
        } catch (Throwable) {
            return new PaymentReconciliationProcessingResult(
                PaymentReconciliationProcessingResult::RECOVERABLE_ERROR
            );
        }

        if ($transition->applied()) {
            return new PaymentReconciliationProcessingResult($processingStatus);
        }

        return new PaymentReconciliationProcessingResult(
            $this->casFailureStatus($transition)
        );
    }

    private function casFailureStatus(
        StatusTransitionResult $transition
    ): string {
        if ($transition->status() === StatusTransitionResult::NOT_FOUND) {
            return PaymentReconciliationProcessingResult::NOT_FOUND;
        }

        if ($transition->status() === StatusTransitionResult::UNEXPECTED_STATE) {
            return PaymentReconciliationProcessingResult::NOT_PROCESSABLE;
        }

        if (in_array($transition->status(), [
            StatusTransitionResult::LEASE_EXPIRED,
            StatusTransitionResult::NOT_OWNER,
            StatusTransitionResult::VERSION_MISMATCH,
        ], true)) {
            return PaymentReconciliationProcessingResult::AUTHORITY_LOST;
        }

        return PaymentReconciliationProcessingResult::CAS_REJECTED;
    }
}
