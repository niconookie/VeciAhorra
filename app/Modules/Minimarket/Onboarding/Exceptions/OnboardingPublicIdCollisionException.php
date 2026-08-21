<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Exceptions;

use RuntimeException;

final class OnboardingPublicIdCollisionException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The generated onboarding identity already exists.');
    }
}
