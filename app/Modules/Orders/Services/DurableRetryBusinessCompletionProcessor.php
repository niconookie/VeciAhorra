<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use Throwable;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryStageProcessorInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionContext;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingFailure;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage;
use VeciAhorra\Modules\Payments\BusinessCompletion\Contracts\BusinessCompletionAttemptProcessorInterface;
use VeciAhorra\Modules\Payments\BusinessCompletion\Contracts\BusinessCompletionReadAuthorityInterface;
use VeciAhorra\Modules\Payments\BusinessCompletion\DTO\BusinessCompletionResult;

final class DurableRetryBusinessCompletionProcessor implements DurableRetryStageProcessorInterface
{
    private const LEASE_SECONDS = 30;

    private const RETRYABLE_REASONS = [
        'unexpected_failure',
        'lease_lost',
    ];

    private const PERMANENT_REASONS = [
        'unsupported_origin',
        'checkout_or_session_missing',
        'empty_order_set',
    ];

    private const MANUAL_REVIEW_REASONS = [
        'reconciliation_changed',
        'legacy_fulfillment_missing',
        'payment_state_conflict',
        'currency_or_approval_mismatch',
        'authority_relationship_mismatch',
        'amount_mismatch',
        'checkout_state_conflict',
        'order_set_mismatch',
        'order_state_conflict',
        'checkout_owner_mismatch',
        'orders_amount_mismatch',
        'payment_identity_conflict',
        'invalid_clp_amount',
    ];

    public function __construct(
        private readonly BusinessCompletionAttemptProcessorInterface $attemptProcessor,
        private readonly BusinessCompletionReadAuthorityInterface $readAuthority
    ) {
    }

    public function stage(): string
    {
        return DurableRetryStage::BUSINESS_COMPLETION;
    }

    public function process(
        DurableRetryExecutionContext $context
    ): DurableRetryProcessingResult {
        if (! $this->validContext($context)) {
            return DurableRetryProcessingResult::outcomeUncertain();
        }

        $attempt = null;
        $attemptFailed = false;
        try {
            $workerId = 'business_' . bin2hex(random_bytes(16));
            $attempt = $this->attemptProcessor->process(
                $context->subjectId(),
                $workerId,
                self::LEASE_SECONDS
            );
        } catch (Throwable) {
            $attemptFailed = true;
        }

        try {
            $authority = $this->readAuthority->findByReconciliation(
                $context->subjectId()
            );
        } catch (Throwable) {
            return DurableRetryProcessingResult::outcomeUncertain();
        }

        return $this->classify($context, $authority, $attempt, $attemptFailed);
    }

    private function validContext(DurableRetryExecutionContext $context): bool
    {
        return $context->stage() === DurableRetryStage::BUSINESS_COMPLETION
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
            && self::LEASE_SECONDS >= 5;
    }

    /**
     * @param array<string, mixed>|null $authority
     */
    private function classify(
        DurableRetryExecutionContext $context,
        ?array $authority,
        ?BusinessCompletionResult $attempt,
        bool $attemptFailed
    ): DurableRetryProcessingResult {
        if ($authority === null) {
            return DurableRetryProcessingResult::outcomeUncertain();
        }

        $confirmedAttempt = $this->confirmedAttempt($authority['attempt_count'] ?? null);
        if (! $this->coherentIdentity($context, $authority)) {
            return DurableRetryProcessingResult::outcomeUncertain($confirmedAttempt);
        }
        if ($confirmedAttempt === null) {
            return DurableRetryProcessingResult::outcomeUncertain();
        }
        if ($confirmedAttempt !== $context->expectedAttemptNumber()) {
            return DurableRetryProcessingResult::outcomeUncertain($confirmedAttempt);
        }

        $status = $authority['status'] ?? null;
        $reason = $authority['last_result_code'] ?? null;
        if (! is_string($status) || ! is_string($reason)) {
            return DurableRetryProcessingResult::outcomeUncertain($confirmedAttempt);
        }

        if ($status === 'completed'
            && $this->completedEvidence($authority)
            && $this->compatibleCompletedAttempt($attempt, $attemptFailed)
        ) {
            return DurableRetryProcessingResult::succeeded($confirmedAttempt);
        }
        if ($attemptFailed) {
            return DurableRetryProcessingResult::outcomeUncertain($confirmedAttempt);
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
                BusinessCompletionResult::PERMANENT_FAILURE,
                $reason
            )
        ) {
            return $this->terminal($confirmedAttempt);
        }
        if ($status === 'manual_review'
            && in_array($reason, self::MANUAL_REVIEW_REASONS, true)
            && $this->compatibleTerminalAttempt(
                $attempt,
                BusinessCompletionResult::MANUAL_REVIEW,
                $reason
            )
        ) {
            return $this->terminal($confirmedAttempt);
        }

        return DurableRetryProcessingResult::outcomeUncertain($confirmedAttempt);
    }

    /**
     * @param array<string, mixed> $authority
     */
    private function coherentIdentity(
        DurableRetryExecutionContext $context,
        array $authority
    ): bool {
        $id = $this->positiveInteger($authority['id'] ?? null);
        $reconciliationId = $this->positiveInteger(
            $authority['reconciliation_id'] ?? null
        );

        return $id !== null
            && $reconciliationId === $context->subjectId()
            && ($context->completionId() === null
                || $id === $context->completionId());
    }

    /**
     * @param array<string, mixed> $authority
     */
    private function completedEvidence(array $authority): bool
    {
        return $this->positiveInteger($authority['payment_id'] ?? null) !== null
            && in_array(
                $authority['fulfillment_method'] ?? null,
                ['pickup', 'delivery'],
                true
            )
            && is_string($authority['completed_at'] ?? null)
            && $authority['completed_at'] !== ''
            && ($authority['last_result_code'] ?? null) === 'completed';
    }

    private function compatibleCompletedAttempt(
        ?BusinessCompletionResult $attempt,
        bool $attemptFailed
    ): bool {
        return $attemptFailed
            || ($attempt !== null
                && in_array($attempt->status, [
                    BusinessCompletionResult::COMPLETED,
                    BusinessCompletionResult::ALREADY_COMPLETED,
                    BusinessCompletionResult::RETRYABLE,
                    BusinessCompletionResult::LEASE_LOST,
                ], true));
    }

    private function compatibleRetryableAttempt(
        ?BusinessCompletionResult $attempt
    ): bool {
        return $attempt !== null
            && in_array($attempt->status, [
                BusinessCompletionResult::RETRYABLE,
                BusinessCompletionResult::LEASE_LOST,
            ], true);
    }

    private function compatibleTerminalAttempt(
        ?BusinessCompletionResult $attempt,
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
