<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationPolicyException;

final class DurableRetryActivationConfiguration
{
    public const MIN_PERCENTAGE = 0;
    public const MAX_PERCENTAGE = 100;
    public const DEFAULT_PERCENTAGE = 0;

    private function __construct(
        private readonly string $stage,
        private readonly int $percentage,
        private readonly string $algorithmVersion
    ) {
    }

    public static function disabled(): self
    {
        return self::reconciliation(
            self::DEFAULT_PERCENTAGE,
            DurableRetryActivationCohort::ALGORITHM_VERSION
        );
    }

    public static function reconciliation(
        int $percentage,
        string $algorithmVersion
    ): self {
        if ($percentage < self::MIN_PERCENTAGE
            || $percentage > self::MAX_PERCENTAGE
        ) {
            throw DurableRetryActivationPolicyException::forCode(
                DurableRetryActivationPolicyException::INVALID_PERCENTAGE
            );
        }
        if ($algorithmVersion
            !== DurableRetryActivationCohort::ALGORITHM_VERSION
        ) {
            throw DurableRetryActivationPolicyException::forCode(
                DurableRetryActivationPolicyException::
                    UNSUPPORTED_ALGORITHM_VERSION
            );
        }

        return new self(
            DurableRetryStage::RECONCILIATION,
            $percentage,
            $algorithmVersion
        );
    }

    public function stage(): string
    {
        return $this->stage;
    }

    public function percentage(): int
    {
        return $this->percentage;
    }

    public function algorithmVersion(): string
    {
        return $this->algorithmVersion;
    }

    public function isDisabled(): bool
    {
        return $this->percentage === self::MIN_PERCENTAGE;
    }

    public function isFullyEnabled(): bool
    {
        return $this->percentage === self::MAX_PERCENTAGE;
    }
}
