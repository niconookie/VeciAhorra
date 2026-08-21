<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Support;

use DateTimeImmutable;
use DateTimeZone;
use VeciAhorra\Modules\Minimarket\Onboarding\Contracts\OnboardingClock;

final class SystemOnboardingClock implements OnboardingClock
{
    public function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
