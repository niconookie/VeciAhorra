<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationContractException;

final class DurableRetryAuthorityIdentity
{
    private function __construct(
        private readonly string $stage,
        private readonly int $subjectId
    ) {
    }

    public static function reconciliation(int $subjectId): self
    {
        if ($subjectId < 1) {
            throw DurableRetryActivationContractException::forCode(
                DurableRetryActivationContractException::INVALID_AUTHORITY_IDENTITY
            );
        }

        return new self(DurableRetryStage::RECONCILIATION, $subjectId);
    }

    public function stage(): string
    {
        return $this->stage;
    }

    public function subjectId(): int
    {
        return $this->subjectId;
    }

    public function equals(self $other): bool
    {
        return $this->stage === $other->stage
            && $this->subjectId === $other->subjectId;
    }

    public function diagnosticKey(): string
    {
        return $this->stage . ':' . $this->subjectId;
    }
}
