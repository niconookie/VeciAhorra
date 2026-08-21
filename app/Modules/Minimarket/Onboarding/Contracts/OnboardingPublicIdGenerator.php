<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Contracts;

interface OnboardingPublicIdGenerator
{
    public function generate(): string;
}
