<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use InvalidArgumentException;

final class DurableRetryProcessingFailure
{
    public const RETRYABLE_FAILURE = 'retryable_failure';
    public const TERMINAL_FAILURE = 'terminal_failure';
    public const OUTCOME_UNCERTAIN = 'outcome_uncertain';

    public const CONFIRMED_RETRYABLE_FAILURE = 'confirmed_retryable_failure';
    public const CONFIRMED_TERMINAL_FAILURE = 'confirmed_terminal_failure';
    public const TECHNICAL_OUTCOME_UNCERTAIN = 'technical_outcome_uncertain';

    private const CODE_BY_CLASSIFICATION = [
        self::RETRYABLE_FAILURE => [self::CONFIRMED_RETRYABLE_FAILURE],
        self::TERMINAL_FAILURE => [self::CONFIRMED_TERMINAL_FAILURE],
        self::OUTCOME_UNCERTAIN => [self::TECHNICAL_OUTCOME_UNCERTAIN],
    ];

    public function __construct(
        private readonly string $classification,
        private readonly string $failureCode,
        private readonly ?int $confirmedAttemptNumber
    ) {
        if (! isset(self::CODE_BY_CLASSIFICATION[$classification])
            || ! in_array(
                $failureCode,
                self::CODE_BY_CLASSIFICATION[$classification],
                true
            )
            || ($confirmedAttemptNumber !== null
                && ($confirmedAttemptNumber < 1 || $confirmedAttemptNumber > 5))
            || ($confirmedAttemptNumber === null
                && $classification !== self::OUTCOME_UNCERTAIN)
        ) {
            throw new InvalidArgumentException('Invalid durable retry processing failure.');
        }
    }

    public static function classifications(): array
    {
        return array_keys(self::CODE_BY_CLASSIFICATION);
    }

    public static function failureCodes(): array
    {
        return array_merge(...array_values(self::CODE_BY_CLASSIFICATION));
    }

    public function classification(): string
    {
        return $this->classification;
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }

    public function confirmedAttemptNumber(): ?int
    {
        return $this->confirmedAttemptNumber;
    }

    public function hasConfirmedAttemptNumber(): bool
    {
        return $this->confirmedAttemptNumber !== null;
    }
}
