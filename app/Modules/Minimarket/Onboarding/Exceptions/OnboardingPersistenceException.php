<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Exceptions;

use RuntimeException;
use Throwable;

final class OnboardingPersistenceException extends RuntimeException
{
    public function __construct(private string $reason, ?Throwable $previous = null)
    {
        if (! in_array($reason, ['identity_generation_failed','persistence_failed','outcome_uncertain'], true)) {
            throw new \InvalidArgumentException('Unknown onboarding persistence reason.');
        }
        parent::__construct('The onboarding application could not be persisted.', 0, $previous);
    }

    public function reason(): string { return $this->reason; }
}
