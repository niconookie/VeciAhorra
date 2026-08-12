<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

final class DurableRetryLegacyAuthorityResult
{
    public const LEGACY = 'legacy';
    public const DURABLE = 'durable';
    public const INDETERMINATE = 'indeterminate';

    private function __construct(
        private readonly string $state,
        private readonly ?string $reason
    ) {
    }

    public static function legacy(): self
    {
        return new self(self::LEGACY, null);
    }

    public static function durable(): self
    {
        return new self(self::DURABLE, null);
    }

    public static function indeterminate(string $reason): self
    {
        DurableRetryIndeterminateReason::assert($reason);

        return new self(self::INDETERMINATE, $reason);
    }

    public function state(): string
    {
        return $this->state;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function diagnosticMessage(): string
    {
        return match ($this->state) {
            self::LEGACY => 'Legacy scheduling authority confirmed.',
            self::DURABLE => 'Durable retry scheduling authority confirmed.',
            self::INDETERMINATE => DurableRetryIndeterminateReason::message(
                (string) $this->reason
            ),
        };
    }

    public function isLegacyAuthorized(): bool
    {
        return $this->state === self::LEGACY;
    }

    public function isDurable(): bool
    {
        return $this->state === self::DURABLE;
    }

    public function isIndeterminate(): bool
    {
        return $this->state === self::INDETERMINATE;
    }

    public function blocksLegacy(): bool
    {
        return ! $this->isLegacyAuthorized();
    }
}
