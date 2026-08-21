<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

interface PublicOnboardingRateLimiter
{
    public function consume(PublicClientAddress $client, ?RateLimitIdentity $identity, string $idempotencyKey): RateLimitDecision;
}
