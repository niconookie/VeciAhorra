<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

use VeciAhorra\Modules\Minimarket\Onboarding\Contracts\CurrentOnboardingTerms;

final class ConfiguredCurrentOnboardingTerms implements CurrentOnboardingTerms
{
    public function __construct(private OnboardingLegalAuthorityValidator $validator) {}

    public function version(): string
    {
        $config = OnboardingLegalConfiguration::fromWordPress();
        $this->validator->validate($config);
        return $config->jointVersion;
    }
}
