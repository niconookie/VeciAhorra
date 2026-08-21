<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public bool $retry,
        public ?int $retryAfter,
        public string $reason
    ) {}
}
