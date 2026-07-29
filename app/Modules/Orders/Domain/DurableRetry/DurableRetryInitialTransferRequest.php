<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use DateTimeImmutable;
use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationContractException;

final class DurableRetryInitialTransferRequest
{
    public const INITIAL_GENERATION = 1;
    public const INITIAL_ATTEMPT = 0;
    public const INITIAL_REASON = DurableRetryReason::RETRYABLE_FAILURE;

    private function __construct(
        private readonly DurableRetryAuthorityIdentity $authority,
        private readonly int $completionId,
        private readonly DateTimeImmutable $scheduledForUtc
    ) {
    }

    public static function reconciliation(
        DurableRetryAuthorityIdentity $authority,
        int $completionId,
        DateTimeImmutable $scheduledForUtc
    ): self {
        if ($authority->stage() !== DurableRetryStage::RECONCILIATION
            || $completionId < 1
            || $completionId !== $authority->subjectId()
            || $scheduledForUtc->getOffset() !== 0
            || $scheduledForUtc->format('u') !== '000000'
        ) {
            throw DurableRetryActivationContractException::forCode(
                DurableRetryActivationContractException::INVALID_INITIAL_TRANSFER_REQUEST
            );
        }

        return new self($authority, $completionId, $scheduledForUtc);
    }

    public function authority(): DurableRetryAuthorityIdentity
    {
        return $this->authority;
    }

    public function generationIdentity(): DurableRetryGenerationIdentity
    {
        return DurableRetryGenerationIdentity::initial($this->authority);
    }

    public function completionId(): int
    {
        return $this->completionId;
    }

    public function generation(): int
    {
        return self::INITIAL_GENERATION;
    }

    public function attemptNumber(): int
    {
        return self::INITIAL_ATTEMPT;
    }

    public function scheduledForUtc(): DateTimeImmutable
    {
        return $this->scheduledForUtc;
    }

    public function scheduledForDatabase(): string
    {
        return $this->scheduledForUtc->format('Y-m-d H:i:s');
    }

    public function reasonCode(): string
    {
        return self::INITIAL_REASON;
    }

    public function equals(self $other): bool
    {
        return $this->authority->equals($other->authority)
            && $this->completionId === $other->completionId
            && $this->scheduledForUtc == $other->scheduledForUtc;
    }

    public function diagnosticKey(): string
    {
        return $this->generationIdentity()->diagnosticKey()
            . ':scheduled-for:' . $this->scheduledForDatabase();
    }
}
