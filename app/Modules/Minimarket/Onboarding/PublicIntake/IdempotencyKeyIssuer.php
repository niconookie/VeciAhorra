<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

interface IdempotencyKeyIssuer
{
    public function issue(): string;
}
