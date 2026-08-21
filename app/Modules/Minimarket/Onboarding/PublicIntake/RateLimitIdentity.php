<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final readonly class RateLimitIdentity
{
    public function __construct(public string $emailHmac, public string $rutHmac) {}
}
