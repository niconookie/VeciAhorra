<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

interface RateLimitLockManager
{
    /** @param list<string> $lockNames */
    public function synchronized(array $lockNames, callable $criticalSection): mixed;
}
