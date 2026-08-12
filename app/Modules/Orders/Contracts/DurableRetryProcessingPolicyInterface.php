<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryNextAttemptDecision;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingFailure;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryScheduleSnapshot;

interface DurableRetryProcessingPolicyInterface
{
    public function decideNextAttempt(
        DurableRetryScheduleSnapshot $claimed,
        DurableRetryProcessingFailure $failure,
        string $decidedAtUtc
    ): DurableRetryNextAttemptDecision;
}
