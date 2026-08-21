<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Support;

use VeciAhorra\Modules\Minimarket\Onboarding\Contracts\OnboardingPublicIdGenerator;

final class RandomOnboardingPublicIdGenerator implements OnboardingPublicIdGenerator
{
    public function generate(): string
    {
        return 'onb_' . bin2hex(random_bytes(20));
    }
}
