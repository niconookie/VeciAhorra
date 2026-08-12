<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use LogicException;

final class DurableRetryActivationConfigurationValue
{
    private function __construct(
        private readonly bool $present,
        private readonly mixed $value
    ) {
    }

    public static function absent(): self
    {
        return new self(false, null);
    }

    public static function present(mixed $value): self
    {
        return new self(true, $value);
    }

    public function isPresent(): bool
    {
        return $this->present;
    }

    public function value(): mixed
    {
        if (! $this->present) {
            throw new LogicException(
                'Absent durable retry activation configuration value has no payload.'
            );
        }

        return $this->value;
    }
}
