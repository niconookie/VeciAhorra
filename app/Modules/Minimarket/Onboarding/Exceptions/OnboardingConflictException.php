<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Exceptions;

use RuntimeException;

final class OnboardingConflictException extends RuntimeException
{
    public function __construct(private string $reason)
    {
        if ($reason !== 'idempotency_conflict') {
            throw new \InvalidArgumentException('Unknown onboarding conflict reason.');
        }
        parent::__construct('The onboarding request conflicts with an existing application.');
    }

    public function reason(): string { return $this->reason; }
}
