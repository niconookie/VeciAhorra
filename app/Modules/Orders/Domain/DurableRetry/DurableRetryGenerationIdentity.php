<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationContractException;

final class DurableRetryGenerationIdentity
{
    private function __construct(
        private readonly DurableRetryAuthorityIdentity $authority,
        private readonly int $generation
    ) {
    }

    public static function initial(
        DurableRetryAuthorityIdentity $authority
    ): self {
        return new self($authority, 1);
    }

    public static function fromAuthority(
        DurableRetryAuthorityIdentity $authority,
        int $generation
    ): self {
        if ($generation < 1) {
            throw DurableRetryActivationContractException::forCode(
                DurableRetryActivationContractException::INVALID_GENERATION_IDENTITY
            );
        }

        return new self($authority, $generation);
    }

    public function authority(): DurableRetryAuthorityIdentity
    {
        return $this->authority;
    }

    public function stage(): string
    {
        return $this->authority->stage();
    }

    public function subjectId(): int
    {
        return $this->authority->subjectId();
    }

    public function generation(): int
    {
        return $this->generation;
    }

    public function isInitial(): bool
    {
        return $this->generation === 1;
    }

    public function equals(self $other): bool
    {
        return $this->authority->equals($other->authority)
            && $this->generation === $other->generation;
    }

    public function diagnosticKey(): string
    {
        return $this->authority->diagnosticKey()
            . ':generation:' . $this->generation;
    }
}
