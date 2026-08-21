<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

use VeciAhorra\Modules\Minimarket\Onboarding\Support\ChileanRutNormalizer;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\OnboardingEmailNormalizer;

final class RateLimitIdentityFactory
{
    public function __construct(
        private OnboardingEmailNormalizer $emails,
        private ChileanRutNormalizer $ruts,
        private RateLimitKeyDeriver $keys
    ) {}

    public function fromRaw(string $email, string $rut): RateLimitIdentity
    {
        return new RateLimitIdentity(
            $this->keys->derive('identity-email', $this->emails->normalize($email)),
            $this->keys->derive('identity-rut', $this->ruts->normalizeAndValidate($rut))
        );
    }
}
