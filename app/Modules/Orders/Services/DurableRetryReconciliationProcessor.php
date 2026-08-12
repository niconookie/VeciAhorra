<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use Throwable;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryStageProcessorInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionContext;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingFailure;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentReconciliationAttemptProcessorInterface;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentReconciliationLeaseAuthorityInterface;
use VeciAhorra\Modules\Payments\Reconciliation\Contracts\PaymentReconciliationReadAuthorityInterface;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\LeaseAcquireResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\PaymentReconciliationProcessingResult;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\ReconciliationLease;
use VeciAhorra\Modules\Payments\Reconciliation\Model\PaymentReconciliation;

final class DurableRetryReconciliationProcessor implements DurableRetryStageProcessorInterface
{
    public function __construct(
        private readonly PaymentReconciliationLeaseAuthorityInterface $claims,
        private readonly PaymentReconciliationAttemptProcessorInterface $attempts,
        private readonly PaymentReconciliationReadAuthorityInterface $reconciliations
    ) {
    }

    public function stage(): string
    {
        return DurableRetryStage::RECONCILIATION;
    }

    public function process(
        DurableRetryExecutionContext $context
    ): DurableRetryProcessingResult {
        if (! $this->validContext($context)) {
            return DurableRetryProcessingResult::outcomeUncertain();
        }

        $lease = null;
        $processing = null;
        try {
            $claim = $this->claims->acquireLease(
                $context->subjectId(),
                $this->claims->newOwner()
            );
            if ($claim->acquired()) {
                $lease = $claim->lease();
                if (! $this->validLease($lease, $context)) {
                    return DurableRetryProcessingResult::outcomeUncertain(
                        $lease?->confirmedAttemptNumber()
                    );
                }
                $processing = $this->attempts->process($lease);
            }
        } catch (Throwable) {
            return $this->classifyAfterRead(
                $context,
                $lease,
                null,
                true
            );
        }

        return $this->classifyAfterRead(
            $context,
            $lease,
            $processing,
            false
        );
    }

    private function validContext(DurableRetryExecutionContext $context): bool
    {
        return $context->stage() === DurableRetryStage::RECONCILIATION
            && $context->scheduleId() > 0
            && $context->subjectId() > 0
            && $context->completionId() === $context->subjectId()
            && $context->generation() > 0
            && $context->previousAttemptNumber() >= 0
            && $context->previousAttemptNumber() <= 4
            && $context->expectedAttemptNumber()
                === $context->previousAttemptNumber() + 1;
    }

    private function validLease(
        ?ReconciliationLease $lease,
        DurableRetryExecutionContext $context
    ): bool {
        return $lease !== null
            && $lease->reconciliationId() === $context->subjectId()
            && $lease->owner() !== ''
            && $lease->version() > 0
            && $lease->confirmedAttemptNumber()
                === $context->expectedAttemptNumber();
    }

    private function classifyAfterRead(
        DurableRetryExecutionContext $context,
        ?ReconciliationLease $lease,
        ?PaymentReconciliationProcessingResult $processing,
        bool $exceptionObserved
    ): DurableRetryProcessingResult {
        try {
            $authority = $this->reconciliations->find($context->subjectId());
        } catch (Throwable) {
            return DurableRetryProcessingResult::outcomeUncertain(
                $lease?->confirmedAttemptNumber()
            );
        }
        if ($authority === null) {
            return DurableRetryProcessingResult::outcomeUncertain();
        }

        $attempt = $authority->attemptCount();
        if ($attempt < 1 || $attempt > 5) {
            return DurableRetryProcessingResult::outcomeUncertain();
        }
        if ($attempt !== $context->expectedAttemptNumber()) {
            return DurableRetryProcessingResult::outcomeUncertain($attempt);
        }

        if ($authority->status() === PaymentReconciliation::STATUS_COMPLETED) {
            return DurableRetryProcessingResult::succeeded($attempt);
        }
        if ($exceptionObserved) {
            return DurableRetryProcessingResult::outcomeUncertain($attempt);
        }
        if ($authority->status() === PaymentReconciliation::STATUS_RETRYABLE) {
            return $this->retryable($attempt);
        }
        if ($authority->status()
            === PaymentReconciliation::STATUS_PERMANENT_FAILURE
        ) {
            return $processing?->status()
                    === PaymentReconciliationProcessingResult::COMPLETION_REJECTED
                ? $this->terminal($attempt)
                : DurableRetryProcessingResult::outcomeUncertain($attempt);
        }
        if ($authority->status() === PaymentReconciliation::STATUS_MANUAL_REVIEW) {
            if ($authority->lastErrorCode() === 'attempts_exhausted') {
                return $this->retryable($attempt);
            }
            if ($processing?->status()
                    === PaymentReconciliationProcessingResult::COMPLETION_REJECTED
                && $processing->completionOutcome()?->targetReconciliationStatus()
                    === PaymentReconciliation::STATUS_MANUAL_REVIEW
            ) {
                return $this->terminal($attempt);
            }
        }

        return DurableRetryProcessingResult::outcomeUncertain($attempt);
    }

    private function retryable(int $attempt): DurableRetryProcessingResult
    {
        return DurableRetryProcessingResult::failed(
            new DurableRetryProcessingFailure(
                DurableRetryProcessingFailure::RETRYABLE_FAILURE,
                DurableRetryProcessingFailure::CONFIRMED_RETRYABLE_FAILURE,
                $attempt
            )
        );
    }

    private function terminal(int $attempt): DurableRetryProcessingResult
    {
        return DurableRetryProcessingResult::failed(
            new DurableRetryProcessingFailure(
                DurableRetryProcessingFailure::TERMINAL_FAILURE,
                DurableRetryProcessingFailure::CONFIRMED_TERMINAL_FAILURE,
                $attempt
            )
        );
    }
}
