<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final readonly class PublicOnboardingRequest
{
    public function __construct(
        public string $accountEmail,
        public string $ownerRut,
        public bool $termsAccepted,
        public string $idempotencyKey
    ) {}
}
