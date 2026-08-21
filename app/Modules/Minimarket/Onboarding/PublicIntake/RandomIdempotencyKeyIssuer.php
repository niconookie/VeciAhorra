<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final class RandomIdempotencyKeyIssuer implements IdempotencyKeyIssuer
{
    public function issue(): string
    {
        return bin2hex(random_bytes(32));
    }
}
