<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final class PublicIntakeException extends \RuntimeException
{
    public function __construct(private string $reason)
    {
        parent::__construct('The public onboarding request could not be processed.');
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
