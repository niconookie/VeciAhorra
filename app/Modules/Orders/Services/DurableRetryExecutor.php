<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use Closure;
use Throwable;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryExecutorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryProcessingPolicyInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryScheduleRepositoryInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryStageProcessorResolverInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionContext;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleCatalog;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextAttemptDecision;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextGenerationPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryPersistenceResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingFailure;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryReason;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStatus;

final class DurableRetryExecutor implements DurableRetryExecutorInterface
{
    public function __construct(
        private readonly DurableRetryScheduleRepositoryInterface $repository,
        private readonly DurableRetryProcessingPolicyInterface $policy,
        private readonly DurableRetryExternalScheduleCoordinatorInterface $coordinator,
        private readonly DurableRetryStageProcessorResolverInterface $processorResolver,
        private readonly Closure $utcNow
    ) {
    }

    public function execute(
        string $hook,
        int $scheduleId,
        int $generation
    ): DurableRetryExecutionResult {
        if ($hook === ''
            || ! in_array($hook, DurableRetryExternalScheduleCatalog::hooks(), true)
            || $scheduleId < 1
            || $generation < 1
        ) {
            return $this->result(
                DurableRetryExecutionResult::INVALID_INVOCATION,
                max(0, $scheduleId),
                max(0, $generation)
            );
        }

        try {
            $read = $this->repository->findById($scheduleId);
        } catch (Throwable) {
            return $this->result(DurableRetryExecutionResult::PERSISTENCE_ERROR, $scheduleId, $generation, intervention: true);
        }
        $snapshot = $read->snapshot();
        if ($snapshot === null) {
            return $this->result(
                $read->code() === DurableRetryPersistenceResult::NOT_FOUND
                    ? DurableRetryExecutionResult::NOT_FOUND
                    : DurableRetryExecutionResult::PERSISTENCE_ERROR,
                $scheduleId,
                $generation,
                intervention: $read->code() !== DurableRetryPersistenceResult::NOT_FOUND
            );
        }
        if ($snapshot->generation() !== $generation) {
            return $this->result(DurableRetryExecutionResult::STALE_GENERATION, $scheduleId, $generation);
        }
        if (DurableRetryExternalScheduleCatalog::hookForStage($snapshot->stage()) !== $hook) {
            return $this->result(DurableRetryExecutionResult::HOOK_MISMATCH, $scheduleId, $generation);
        }
        if ($snapshot->status() !== DurableRetryStatus::SCHEDULED) {
            return $this->stateResult($snapshot, $scheduleId, $generation);
        }
        if ($snapshot->toArray()['scheduled_action_id'] === null) {
            return $this->result(DurableRetryExecutionResult::DURABLE_INCONSISTENCY, $scheduleId, $generation, intervention: true);
        }

        $processor = $this->processorResolver->resolve($snapshot->stage());
        if ($processor->stage() !== $snapshot->stage()) {
            return $this->result(DurableRetryExecutionResult::PROCESSOR_MISMATCH, $scheduleId, $generation);
        }

        $claimedAt = $this->now();
        if ($claimedAt === null) {
            return $this->result(DurableRetryExecutionResult::PERSISTENCE_ERROR, $scheduleId, $generation, intervention: true);
        }
        $claimed = DurableRetryScheduleSnapshot::fromArray(array_replace(
            $snapshot->toArray(),
            [
                'status' => DurableRetryStatus::CLAIMED,
                'version' => $snapshot->version() + 1,
                'claimed_at' => $claimedAt,
                'updated_at' => $claimedAt,
            ]
        ));
        try {
            $claim = $this->repository->transition($snapshot, $claimed);
        } catch (Throwable) {
            return $this->result(DurableRetryExecutionResult::PERSISTENCE_ERROR, $scheduleId, $generation, intervention: true);
        }
        if ($claim->code() !== DurableRetryPersistenceResult::APPLIED
            || $claim->snapshot()?->status() !== DurableRetryStatus::CLAIMED
        ) {
            return $this->classifyClaimLoss($scheduleId, $generation);
        }
        $claimed = $claim->snapshot();
        $fields = $claimed->toArray();
        $expectedAttempt = $fields['attempt_number'] + 1;
        $context = new DurableRetryExecutionContext(
            $claimed->id(),
            $claimed->stage(),
            $claimed->subjectId(),
            $fields['completion_id'],
            $claimed->generation(),
            $fields['attempt_number'],
            $expectedAttempt,
            $claimedAt
        );

        try {
            $processing = $processor->process($context);
        } catch (Throwable) {
            $processing = DurableRetryProcessingResult::outcomeUncertain();
        }
        $confirmedAttempt = $processing->confirmedAttemptNumber();
        if (($processing->classification()
                !== DurableRetryProcessingFailure::OUTCOME_UNCERTAIN
                && $confirmedAttempt === null)
            || ($confirmedAttempt !== null
                && $confirmedAttempt !== $expectedAttempt)
        ) {
            return $this->result(DurableRetryExecutionResult::PROCESSING_CONTRACT_ERROR, $scheduleId, $generation, processed: true, intervention: true);
        }

        $decidedAt = $this->now();
        if ($decidedAt === null) {
            return $this->result(DurableRetryExecutionResult::PERSISTENCE_ERROR, $scheduleId, $generation, processed: true, intervention: true);
        }
        if ($processing->succeededProcessing()) {
            return $this->close(
                $claimed,
                DurableRetryStatus::CONSUMED,
                DurableRetryReason::RETRY_CONSUMED,
                $decidedAt,
                DurableRetryExecutionResult::PROCESSED
            );
        }

        $failure = $processing->failure();
        if ($failure === null) {
            return $this->result(DurableRetryExecutionResult::PROCESSING_CONTRACT_ERROR, $scheduleId, $generation, processed: true, intervention: true);
        }
        try {
            $decision = $this->policy->decideNextAttempt($claimed, $failure, $decidedAt);
        } catch (Throwable) {
            return $this->result(DurableRetryExecutionResult::PROCESSING_CONTRACT_ERROR, $scheduleId, $generation, processed: true, intervention: true);
        }

        return match ($failure->classification()) {
            DurableRetryProcessingFailure::TERMINAL_FAILURE =>
                $decision->code() === DurableRetryNextAttemptDecision::TERMINAL
                    ? $this->close($claimed, DurableRetryStatus::FAILED, DurableRetryReason::PROCESSING_TERMINAL_FAILURE, $decidedAt, DurableRetryExecutionResult::TERMINAL_FAILURE)
                    : $this->result(DurableRetryExecutionResult::PROCESSING_CONTRACT_ERROR, $scheduleId, $generation, processed: true, intervention: true),
            DurableRetryProcessingFailure::OUTCOME_UNCERTAIN =>
                $decision->code() === DurableRetryNextAttemptDecision::UNCERTAIN
                    ? $this->close($claimed, DurableRetryStatus::ORPHANED, DurableRetryReason::PROCESSING_OUTCOME_UNCERTAIN, $decidedAt, DurableRetryExecutionResult::OUTCOME_UNCERTAIN, true)
                    : $this->result(DurableRetryExecutionResult::PROCESSING_CONTRACT_ERROR, $scheduleId, $generation, processed: true, intervention: true),
            DurableRetryProcessingFailure::RETRYABLE_FAILURE =>
                $this->retryable($claimed, $decision, $decidedAt),
            default => $this->result(DurableRetryExecutionResult::PROCESSING_CONTRACT_ERROR, $scheduleId, $generation, processed: true, intervention: true),
        };
    }

    private function retryable(
        DurableRetryScheduleSnapshot $claimed,
        DurableRetryNextAttemptDecision $decision,
        string $decidedAt
    ): DurableRetryExecutionResult {
        if ($decision->code() === DurableRetryNextAttemptDecision::EXHAUSTED) {
            return $this->close($claimed, DurableRetryStatus::FAILED, DurableRetryReason::PROCESSING_ATTEMPTS_EXHAUSTED, $decidedAt, DurableRetryExecutionResult::ATTEMPTS_EXHAUSTED);
        }
        if ($decision->code() !== DurableRetryNextAttemptDecision::RETRY) {
            return $this->result(DurableRetryExecutionResult::PROCESSING_CONTRACT_ERROR, $claimed->id(), $claimed->generation(), processed: true, intervention: true);
        }
        try {
            $next = $this->repository->supersedeAndCreateNextGeneration($claimed, $decision, $decidedAt);
        } catch (Throwable) {
            return $this->result(DurableRetryExecutionResult::PERSISTENCE_ERROR, $claimed->id(), $claimed->generation(), processed: true, intervention: true);
        }
        if (! $next->succeeded() || $next->successor() === null) {
            return $this->result(
                $next->code() === DurableRetryNextGenerationPersistenceResult::DURABLE_INCONSISTENCY
                    ? DurableRetryExecutionResult::DURABLE_INCONSISTENCY
                    : DurableRetryExecutionResult::PERSISTENCE_ERROR,
                $claimed->id(),
                $claimed->generation(),
                processed: true,
                intervention: true
            );
        }
        $successor = $next->successor();
        try {
            $coordination = $this->coordinator->coordinate(
                $successor->id(),
                $successor->generation()
            );
        } catch (Throwable) {
            return $this->result(DurableRetryExecutionResult::COORDINATION_ERROR, $claimed->id(), $claimed->generation(), $successor->id(), $successor->generation(), processed: true, prepared: true, intervention: true);
        }
        return $this->result(
            $coordination->succeeded()
                ? DurableRetryExecutionResult::RETRY_SCHEDULED
                : DurableRetryExecutionResult::RETRY_PREPARED,
            $claimed->id(),
            $claimed->generation(),
            $successor->id(),
            $successor->generation(),
            success: $coordination->succeeded(),
            processed: true,
            prepared: true,
            coordinated: $coordination->succeeded(),
            intervention: ! $coordination->succeeded()
        );
    }

    private function close(
        DurableRetryScheduleSnapshot $claimed,
        string $status,
        string $reason,
        string $at,
        string $resultCode,
        bool $intervention = false
    ): DurableRetryExecutionResult {
        $changes = [
            'status' => $status,
            'active_slot' => null,
            'version' => $claimed->version() + 1,
            'reason_code' => $reason,
            'terminal_at' => $at,
            'updated_at' => $at,
        ];
        if ($status === DurableRetryStatus::CONSUMED) {
            $changes['consumed_at'] = $at;
        }
        $target = DurableRetryScheduleSnapshot::fromArray(
            array_replace($claimed->toArray(), $changes)
        );
        try {
            $closed = $this->repository->transition($claimed, $target);
        } catch (Throwable) {
            return $this->result(DurableRetryExecutionResult::PERSISTENCE_ERROR, $claimed->id(), $claimed->generation(), processed: true, intervention: true);
        }
        if ($closed->code() === DurableRetryPersistenceResult::APPLIED
            && $closed->snapshot()?->status() === $status
        ) {
            return $this->result($resultCode, $claimed->id(), $claimed->generation(), success: true, processed: true, intervention: $intervention);
        }
        try {
            $current = $this->repository->findById($claimed->id())->snapshot();
        } catch (Throwable) {
            $current = null;
        }
        if ($current?->generation() === $claimed->generation()
            && $current->status() === $status
        ) {
            return $this->result($resultCode, $claimed->id(), $claimed->generation(), success: true, processed: true, intervention: $intervention);
        }

        return $this->result(
            $current === null
                ? DurableRetryExecutionResult::PERSISTENCE_ERROR
                : DurableRetryExecutionResult::DURABLE_INCONSISTENCY,
            $claimed->id(),
            $claimed->generation(),
            processed: true,
            intervention: true
        );
    }

    private function classifyClaimLoss(int $scheduleId, int $generation): DurableRetryExecutionResult
    {
        try {
            $current = $this->repository->findById($scheduleId)->snapshot();
        } catch (Throwable) {
            $current = null;
        }
        if ($current === null) {
            return $this->result(DurableRetryExecutionResult::PERSISTENCE_ERROR, $scheduleId, $generation, intervention: true);
        }
        if ($current->generation() !== $generation) {
            return $this->result(DurableRetryExecutionResult::STALE_GENERATION, $scheduleId, $generation);
        }

        return $this->stateResult($current, $scheduleId, $generation, true);
    }

    private function stateResult(
        DurableRetryScheduleSnapshot $snapshot,
        int $scheduleId,
        int $generation,
        bool $claimConflict = false
    ): DurableRetryExecutionResult {
        $code = match ($snapshot->status()) {
            DurableRetryStatus::CLAIMED => DurableRetryExecutionResult::ALREADY_CLAIMED,
            DurableRetryStatus::CONSUMED => DurableRetryExecutionResult::ALREADY_COMPLETED,
            DurableRetryStatus::FAILED,
            DurableRetryStatus::ORPHANED,
            DurableRetryStatus::SUPERSEDED,
            DurableRetryStatus::CANCELLED => DurableRetryExecutionResult::ALREADY_TERMINAL,
            DurableRetryStatus::DISPATCHING => DurableRetryExecutionResult::INELIGIBLE_STATE,
            default => $claimConflict
                ? DurableRetryExecutionResult::CLAIM_CONFLICT
                : DurableRetryExecutionResult::INELIGIBLE_STATE,
        };

        return $this->result($code, $scheduleId, $generation);
    }

    private function now(): ?string
    {
        try {
            $value = ($this->utcNow)();
            if (! is_string($value)) {
                return null;
            }
            DurableRetryExternalScheduleCatalog::timestamp($value);

            return $value;
        } catch (Throwable) {
            return null;
        }
    }

    private function result(
        string $code,
        int $scheduleId,
        int $generation,
        ?int $nextScheduleId = null,
        ?int $nextGeneration = null,
        bool $success = false,
        bool $processed = false,
        bool $prepared = false,
        bool $coordinated = false,
        bool $intervention = false
    ): DurableRetryExecutionResult {
        return new DurableRetryExecutionResult(
            $code,
            $scheduleId,
            $generation,
            $nextScheduleId,
            $nextGeneration,
            $success,
            $processed,
            $prepared,
            $coordinated,
            $intervention
        );
    }
}
