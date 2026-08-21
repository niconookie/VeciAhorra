<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Exceptions;

use DomainException;

final class OnboardingInputException extends DomainException
{
    public function __construct(private string $reason)
    {
        if (! in_array($reason, ['invalid_email','invalid_rut','terms_not_accepted','terms_version_unavailable','invalid_idempotency_key'], true)) {
            throw new \InvalidArgumentException('Unknown onboarding input reason.');
        }
        parent::__construct('The onboarding input is invalid.');
    }

    public function reason(): string { return $this->reason; }
}
