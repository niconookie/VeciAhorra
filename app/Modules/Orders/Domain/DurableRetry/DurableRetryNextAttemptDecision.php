<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use InvalidArgumentException;

final class DurableRetryNextAttemptDecision
{
    public const RETRY = 'retry';
    public const EXHAUSTED = 'exhausted';
    public const TERMINAL = 'terminal';
    public const UNCERTAIN = 'uncertain';

    private function __construct(
        private readonly string $code,
        private readonly ?int $nextGeneration,
        private readonly ?int $nextAttemptNumber,
        private readonly ?string $scheduledForUtc,
        private readonly ?int $backoffSeconds,
        private readonly ?string $finalStatus,
        private readonly ?string $reasonCode,
        private readonly bool $interventionRequired
    ) {
        if ($code === self::RETRY) {
            if ($nextGeneration === null
                || $nextGeneration < 1
                || $nextAttemptNumber === null
                || $nextAttemptNumber < 1
                || $nextAttemptNumber > 4
                || $scheduledForUtc === null
                || ! in_array($backoffSeconds, [60, 120, 240, 480], true)
                || $finalStatus !== null
                || $reasonCode !== null
                || $interventionRequired
            ) {
                throw new InvalidArgumentException('Invalid retry decision.');
            }

            return;
        }

        $closed = [
            self::EXHAUSTED => [
                DurableRetryStatus::FAILED,
                DurableRetryReason::PROCESSING_ATTEMPTS_EXHAUSTED,
                false,
            ],
            self::TERMINAL => [
                DurableRetryStatus::FAILED,
                DurableRetryReason::PROCESSING_TERMINAL_FAILURE,
                false,
            ],
            self::UNCERTAIN => [
                DurableRetryStatus::ORPHANED,
                DurableRetryReason::PROCESSING_OUTCOME_UNCERTAIN,
                true,
            ],
        ];
        if (! isset($closed[$code])
            || [$finalStatus, $reasonCode, $interventionRequired] !== $closed[$code]
            || $nextGeneration !== null
            || $nextAttemptNumber !== null
            || $scheduledForUtc !== null
            || $backoffSeconds !== null
        ) {
            throw new InvalidArgumentException('Invalid terminal retry decision.');
        }
    }

    public static function retry(
        int $nextGeneration,
        int $nextAttemptNumber,
        string $scheduledForUtc,
        int $backoffSeconds
    ): self {
        return new self(
            self::RETRY,
            $nextGeneration,
            $nextAttemptNumber,
            $scheduledForUtc,
            $backoffSeconds,
            null,
            null,
            false
        );
    }

    public static function exhausted(): self
    {
        return self::closed(
            self::EXHAUSTED,
            DurableRetryStatus::FAILED,
            DurableRetryReason::PROCESSING_ATTEMPTS_EXHAUSTED,
            false
        );
    }

    public static function terminal(): self
    {
        return self::closed(
            self::TERMINAL,
            DurableRetryStatus::FAILED,
            DurableRetryReason::PROCESSING_TERMINAL_FAILURE,
            false
        );
    }

    public static function uncertain(): self
    {
        return self::closed(
            self::UNCERTAIN,
            DurableRetryStatus::ORPHANED,
            DurableRetryReason::PROCESSING_OUTCOME_UNCERTAIN,
            true
        );
    }

    private static function closed(
        string $code,
        string $status,
        string $reason,
        bool $intervention
    ): self {
        return new self(
            $code,
            null,
            null,
            null,
            null,
            $status,
            $reason,
            $intervention
        );
    }

    public function code(): string
    {
        return $this->code;
    }

    public function nextGeneration(): ?int
    {
        return $this->nextGeneration;
    }

    public function nextAttemptNumber(): ?int
    {
        return $this->nextAttemptNumber;
    }

    public function scheduledForUtc(): ?string
    {
        return $this->scheduledForUtc;
    }

    public function backoffSeconds(): ?int
    {
        return $this->backoffSeconds;
    }

    public function finalStatus(): ?string
    {
        return $this->finalStatus;
    }

    public function reasonCode(): ?string
    {
        return $this->reasonCode;
    }

    public function createsNextGeneration(): bool
    {
        return $this->code === self::RETRY;
    }

    public function interventionRequired(): bool
    {
        return $this->interventionRequired;
    }
}
