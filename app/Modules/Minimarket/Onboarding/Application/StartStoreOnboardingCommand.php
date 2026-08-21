<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Application;

final readonly class StartStoreOnboardingCommand
{
    public function __construct(
        public string $accountEmail,
        public string $ownerRut,
        public string $idempotencyKey,
        public bool $termsAccepted
    ) {}
}
