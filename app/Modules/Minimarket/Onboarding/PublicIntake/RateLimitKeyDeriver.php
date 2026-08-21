<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

interface RateLimitKeyDeriver
{
    public function derive(string $domain, string $value): string;
}
