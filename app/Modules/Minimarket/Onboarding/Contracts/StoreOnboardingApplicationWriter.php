<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Contracts;

use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplication;

interface StoreOnboardingApplicationWriter
{
    public function createProvisioning(array $data): StoreOnboardingApplication;
}
