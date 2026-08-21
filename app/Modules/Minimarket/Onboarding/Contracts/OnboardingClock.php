<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Contracts;

use DateTimeImmutable;

interface OnboardingClock
{
    public function nowUtc(): DateTimeImmutable;
}
