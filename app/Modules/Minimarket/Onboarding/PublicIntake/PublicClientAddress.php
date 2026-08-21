<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final readonly class PublicClientAddress
{
    public function __construct(public string $networkBytes, public int $prefixLength) {}
}
