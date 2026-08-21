<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final class OnboardingLegalLinkProvider
{
    public function __construct(private OnboardingLegalAuthorityValidator $validator) {}

    /** @return array{terms_url:string,privacy_url:string,version:string} */
    public function links(): array
    {
        $config = OnboardingLegalConfiguration::fromWordPress();
        return $this->validator->validate($config) + ['version' => $config->jointVersion];
    }
}
