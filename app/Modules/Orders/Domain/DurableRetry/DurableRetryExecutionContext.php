<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use InvalidArgumentException;

final class DurableRetryExecutionContext
{
    public function __construct(
        private readonly int $scheduleId,
        private readonly string $stage,
        private readonly int $subjectId,
        private readonly ?int $completionId,
        private readonly int $generation,
        private readonly int $previousAttemptNumber,
        private readonly int $expectedAttemptNumber,
        private readonly string $claimedAtUtc
    ) {
        DurableRetryStage::assert($stage);
        if ($scheduleId < 1
            || $subjectId < 1
            || ($completionId !== null && $completionId < 1)
            || $generation < 1
            || $previousAttemptNumber < 0
            || $previousAttemptNumber >= PHP_INT_MAX
            || $expectedAttemptNumber !== $previousAttemptNumber + 1
        ) {
            throw new InvalidArgumentException('Invalid durable retry execution context.');
        }
        DurableRetryExternalScheduleCatalog::timestamp($claimedAtUtc);
    }

    public function scheduleId(): int { return $this->scheduleId; }
    public function stage(): string { return $this->stage; }
    public function subjectId(): int { return $this->subjectId; }
    public function completionId(): ?int { return $this->completionId; }
    public function generation(): int { return $this->generation; }
    public function previousAttemptNumber(): int { return $this->previousAttemptNumber; }
    public function expectedAttemptNumber(): int { return $this->expectedAttemptNumber; }
    public function claimedAtUtc(): string { return $this->claimedAtUtc; }
}
