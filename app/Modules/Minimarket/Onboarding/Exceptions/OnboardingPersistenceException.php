<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Exceptions;

use RuntimeException;

final class OnboardingPersistenceException extends RuntimeException
{
    public function __construct(private string $reason)
    {
        if (! in_array($reason, ['identity_generation_failed','persistence_failed','outcome_uncertain'], true)) {
            throw new \InvalidArgumentException('Unknown onboarding persistence reason.');
        }
        parent::__construct('The onboarding application could not be persisted.');
    }

    public function reason(): string { return $this->reason; }
}
