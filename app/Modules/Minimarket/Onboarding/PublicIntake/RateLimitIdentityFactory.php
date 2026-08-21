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
        $normalizedEmail = $this->emails->normalize($email);
        $normalizedRut = $this->ruts->normalizeAndValidate($rut);
        return new RateLimitIdentity(
            $this->keys->derive('identity-email', $normalizedEmail),
            $this->keys->derive('identity-rut', $normalizedRut),
            $normalizedEmail,
            $normalizedRut
        );
    }
}
