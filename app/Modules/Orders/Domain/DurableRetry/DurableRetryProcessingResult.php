<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use InvalidArgumentException;

final class DurableRetryProcessingResult
{
    public const SUCCEEDED = 'succeeded';

    private function __construct(
        private readonly string $classification,
        private readonly ?int $confirmedAttemptNumber,
        private readonly ?DurableRetryProcessingFailure $failure
    ) {
        if ($classification === self::SUCCEEDED) {
            if ($failure !== null) {
                throw new InvalidArgumentException('Invalid successful processing result.');
            }
        } elseif ($failure === null
            || $classification !== $failure->classification()
            || $confirmedAttemptNumber !== $failure->confirmedAttemptNumber()
        ) {
            throw new InvalidArgumentException('Invalid failed processing result.');
        }
        if ($confirmedAttemptNumber !== null
            && ($confirmedAttemptNumber < 1 || $confirmedAttemptNumber > 5)
        ) {
            throw new InvalidArgumentException('Invalid confirmed attempt number.');
        }
        if ($confirmedAttemptNumber === null
            && $classification !== DurableRetryProcessingFailure::OUTCOME_UNCERTAIN
        ) {
            throw new InvalidArgumentException('Missing confirmed attempt number.');
        }
    }

    public static function succeeded(int $confirmedAttemptNumber): self
    {
        return new self(self::SUCCEEDED, $confirmedAttemptNumber, null);
    }

    public static function failed(DurableRetryProcessingFailure $failure): self
    {
        return new self(
            $failure->classification(),
            $failure->confirmedAttemptNumber(),
            $failure
        );
    }

    public static function outcomeUncertain(
        ?int $confirmedAttemptNumber = null
    ): self {
        return self::failed(new DurableRetryProcessingFailure(
            DurableRetryProcessingFailure::OUTCOME_UNCERTAIN,
            DurableRetryProcessingFailure::TECHNICAL_OUTCOME_UNCERTAIN,
            $confirmedAttemptNumber
        ));
    }

    public function classification(): string
    {
        return $this->classification;
    }

    public function confirmedAttemptNumber(): ?int
    {
        return $this->confirmedAttemptNumber;
    }

    public function hasConfirmedAttemptNumber(): bool
    {
        return $this->confirmedAttemptNumber !== null;
    }

    public function failure(): ?DurableRetryProcessingFailure
    {
        return $this->failure;
    }

    public function succeededProcessing(): bool
    {
        return $this->classification === self::SUCCEEDED;
    }
}
