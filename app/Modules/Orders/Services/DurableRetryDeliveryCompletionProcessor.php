<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Delivery\Completion\Contracts\DeliveryCompletionAttemptProcessorInterface;
use VeciAhorra\Modules\Delivery\Completion\Contracts\DeliveryCompletionReadAuthorityInterface;
use VeciAhorra\Modules\Delivery\Completion\DTO\DeliveryCompletionResult;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryStageProcessorInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionContext;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingFailure;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;

final class DurableRetryDeliveryCompletionProcessor implements DurableRetryStageProcessorInterface
{
    private const LEASE_SECONDS = 600;

    private const RETRYABLE_REASONS = [
        'delivery_verification_failed',
        'lease_lost',
        'unexpected_failure',
    ];

    private const PERMANENT_REASONS = [
        'business_completion_not_completed',
    ];

    private const MANUAL_REVIEW_REASONS = [
        'fulfillment_snapshot_invalid',
        'order_snapshot_invalid',
        'snapshot_order_missing',
        'snapshot_order_not_paid',
        'delivery_identity_conflict',
    ];

    public function __construct(
        private readonly DeliveryCompletionAttemptProcessorInterface $attemptProcessor,
        private readonly DeliveryCompletionReadAuthorityInterface $readAuthority
    ) {
    }

    public function stage(): string
    {
        return DurableRetryStage::DELIVERY_COMPLETION;
    }

    public function process(
        DurableRetryExecutionContext $context
    ): DurableRetryProcessingResult {
        if (! $this->validContext($context)) {
            return DurableRetryProcessingResult::outcomeUncertain();
        }

        $attempt = null;
        $infrastructureFailure = false;
        try {
            $attempt = $this->attemptProcessor->process(
                $context->subjectId(),
                'worker_' . bin2hex(random_bytes(16)),
                self::LEASE_SECONDS
            );
        } catch (PersistenceException) {
            $infrastructureFailure = true;
        }

        try {
            $authority = $this->readAuthority->findByBusinessCompletion(
                $context->subjectId()
            );
        } catch (PersistenceException) {
            return DurableRetryProcessingResult::outcomeUncertain();
        }

        return $this->classify(
            $context,
            $authority,
            $attempt,
            $infrastructureFailure
        );
    }

    private function validContext(DurableRetryExecutionContext $context): bool
    {
        return $context->stage() === DurableRetryStage::DELIVERY_COMPLETION
            && $context->scheduleId() > 0
            && $context->subjectId() > 0
            && $context->generation() > 0
            && $context->previousAttemptNumber() >= 0
            && $context->previousAttemptNumber() <= 4
            && $context->expectedAttemptNumber()
                === $context->previousAttemptNumber() + 1
            && $context->expectedAttemptNumber() >= 1
            && $context->expectedAttemptNumber() <= 5
            && ($context->completionId() === null
                || $context->completionId() > 0)
            && self::LEASE_SECONDS >= 1
            && self::LEASE_SECONDS <= 3600;
    }

    /**
     * @param array<string, mixed>|null $authority
     */
    private function classify(
        DurableRetryExecutionContext $context,
        ?array $authority,
        ?DeliveryCompletionResult $attempt,
        bool $infrastructureFailure
    ): DurableRetryProcessingResult {
        if ($authority === null) {
            return DurableRetryProcessingResult::outcomeUncertain();
        }

        $confirmedAttempt = $this->confirmedAttempt(
            $authority['attempt_count'] ?? null
        );
        if (! $this->coherentIdentity($context, $authority)) {
            return DurableRetryProcessingResult::outcomeUncertain(
                $confirmedAttempt
            );
        }
        if ($confirmedAttempt === null) {
            return DurableRetryProcessingResult::outcomeUncertain();
        }
        if ($confirmedAttempt !== $context->expectedAttemptNumber()) {
            return DurableRetryProcessingResult::outcomeUncertain(
                $confirmedAttempt
            );
        }

        $status = $authority['completion_status'] ?? null;
        $reason = $authority['last_result_code'] ?? null;
        if (! is_string($status) || ! is_string($reason)) {
            return DurableRetryProcessingResult::outcomeUncertain(
                $confirmedAttempt
            );
        }

        if ($this->completedEvidence($authority)
            && $this->compatibleSuccessfulAttempt(
                $attempt,
                $infrastructureFailure
            )
        ) {
            return DurableRetryProcessingResult::succeeded($confirmedAttempt);
        }
        if ($infrastructureFailure) {
            return DurableRetryProcessingResult::outcomeUncertain(
                $confirmedAttempt
            );
        }
        if ($status === 'retryable'
            && in_array($reason, self::RETRYABLE_REASONS, true)
            && $this->compatibleRetryableAttempt($attempt)
        ) {
            return $this->retryable($confirmedAttempt);
        }
        if ($status === 'permanent_failure'
            && in_array($reason, self::PERMANENT_REASONS, true)
            && $this->compatibleTerminalAttempt(
                $attempt,
                DeliveryCompletionResult::PERMANENT_FAILURE,
                $reason
            )
        ) {
            return $this->terminal($confirmedAttempt);
        }
        if ($status === 'manual_review'
            && in_array($reason, self::MANUAL_REVIEW_REASONS, true)
            && $this->compatibleTerminalAttempt(
                $attempt,
                DeliveryCompletionResult::MANUAL_REVIEW,
                $reason
            )
        ) {
            return $this->terminal($confirmedAttempt);
        }

        return DurableRetryProcessingResult::outcomeUncertain(
            $confirmedAttempt
        );
    }

    /**
     * @param array<string, mixed> $authority
     */
    private function coherentIdentity(
        DurableRetryExecutionContext $context,
        array $authority
    ): bool {
        $id = $this->positiveInteger($authority['id'] ?? null);
        $businessCompletionId = $this->positiveInteger(
            $authority['business_completion_id'] ?? null
        );

        return $id !== null
            && $businessCompletionId === $context->subjectId()
            && ($context->completionId() === null
                || $id === $context->completionId());
    }

    /**
     * @param array<string, mixed> $authority
     */
    private function completedEvidence(array $authority): bool
    {
        $status = $authority['completion_status'] ?? null;
        $reason = $authority['last_result_code'] ?? null;

        return is_string($authority['completed_at'] ?? null)
            && $authority['completed_at'] !== ''
            && (($status === 'completed'
                    && $reason === 'deliveries_materialized')
                || ($status === 'not_required' && $reason === 'pickup'));
    }

    private function compatibleSuccessfulAttempt(
        ?DeliveryCompletionResult $attempt,
        bool $infrastructureFailure
    ): bool {
        return $infrastructureFailure
            || ($attempt !== null
                && in_array($attempt->status, [
                    DeliveryCompletionResult::COMPLETED,
                    DeliveryCompletionResult::ALREADY_COMPLETED,
                    DeliveryCompletionResult::NOT_REQUIRED,
                    DeliveryCompletionResult::RETRYABLE_FAILURE,
                    DeliveryCompletionResult::LEASE_LOST,
                ], true));
    }

    private function compatibleRetryableAttempt(
        ?DeliveryCompletionResult $attempt
    ): bool {
        return $attempt !== null
            && in_array($attempt->status, [
                DeliveryCompletionResult::RETRYABLE_FAILURE,
                DeliveryCompletionResult::LEASE_LOST,
            ], true);
    }

    private function compatibleTerminalAttempt(
        ?DeliveryCompletionResult $attempt,
        string $status,
        string $reason
    ): bool {
        return $attempt !== null
            && $attempt->status === $status
            && hash_equals($reason, $attempt->reason);
    }

    private function confirmedAttempt(mixed $value): ?int
    {
        $attempt = $this->integer($value);

        return $attempt !== null && $attempt >= 1 && $attempt <= 5
            ? $attempt
            : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $integer = $this->integer($value);

        return $integer !== null && $integer > 0 ? $integer : null;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value)
            || preg_match('/^(0|-?[1-9]\d*)$/D', $value) !== 1
        ) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) ? $integer : null;
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
