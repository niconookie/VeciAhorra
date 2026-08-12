<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use InvalidArgumentException;

final class DurableRetryExecutionResult
{
    public const PROCESSED = 'processed';
    public const RETRY_SCHEDULED = 'retry_scheduled';
    public const RETRY_PREPARED = 'retry_prepared';
    public const ATTEMPTS_EXHAUSTED = 'attempts_exhausted';
    public const TERMINAL_FAILURE = 'terminal_failure';
    public const OUTCOME_UNCERTAIN = 'outcome_uncertain';
    public const NOT_FOUND = 'not_found';
    public const INVALID_INVOCATION = 'invalid_invocation';
    public const HOOK_MISMATCH = 'hook_mismatch';
    public const PROCESSOR_MISMATCH = 'processor_mismatch';
    public const STALE_GENERATION = 'stale_generation';
    public const INELIGIBLE_STATE = 'ineligible_state';
    public const ALREADY_CLAIMED = 'already_claimed';
    public const ALREADY_COMPLETED = 'already_completed';
    public const ALREADY_TERMINAL = 'already_terminal';
    public const CLAIM_CONFLICT = 'claim_conflict';
    public const PROCESSING_CONTRACT_ERROR = 'processing_contract_error';
    public const PERSISTENCE_ERROR = 'persistence_error';
    public const COORDINATION_ERROR = 'coordination_error';
    public const DURABLE_INCONSISTENCY = 'durable_inconsistency';

    private const ALL = [
        self::PROCESSED, self::RETRY_SCHEDULED, self::RETRY_PREPARED,
        self::ATTEMPTS_EXHAUSTED, self::TERMINAL_FAILURE,
        self::OUTCOME_UNCERTAIN, self::NOT_FOUND, self::INVALID_INVOCATION,
        self::HOOK_MISMATCH, self::PROCESSOR_MISMATCH, self::STALE_GENERATION,
        self::INELIGIBLE_STATE, self::ALREADY_CLAIMED,
        self::ALREADY_COMPLETED, self::ALREADY_TERMINAL,
        self::CLAIM_CONFLICT, self::PROCESSING_CONTRACT_ERROR,
        self::PERSISTENCE_ERROR, self::COORDINATION_ERROR,
        self::DURABLE_INCONSISTENCY,
    ];

    public function __construct(
        private readonly string $code,
        private readonly int $scheduleId,
        private readonly int $generation,
        private readonly ?int $nextScheduleId = null,
        private readonly ?int $nextGeneration = null,
        private readonly bool $succeeded = false,
        private readonly bool $processorInvoked = false,
        private readonly bool $retryPrepared = false,
        private readonly bool $externallyCoordinated = false,
        private readonly bool $interventionRequired = false
    ) {
        if (! in_array($code, self::ALL, true)
            || $scheduleId < 0
            || $generation < 0
            || (($nextScheduleId === null) !== ($nextGeneration === null))
            || ($nextScheduleId !== null && ($nextScheduleId < 1 || $nextGeneration < 1))
            || ($externallyCoordinated && ! $retryPrepared)
        ) {
            throw new InvalidArgumentException('Invalid durable retry execution result.');
        }
    }

    public function code(): string { return $this->code; }
    public function scheduleId(): int { return $this->scheduleId; }
    public function generation(): int { return $this->generation; }
    public function nextScheduleId(): ?int { return $this->nextScheduleId; }
    public function nextGeneration(): ?int { return $this->nextGeneration; }
    public function succeeded(): bool { return $this->succeeded; }
    public function processorInvoked(): bool { return $this->processorInvoked; }
    public function retryPrepared(): bool { return $this->retryPrepared; }
    public function externallyCoordinated(): bool { return $this->externallyCoordinated; }
    public function interventionRequired(): bool { return $this->interventionRequired; }
}
