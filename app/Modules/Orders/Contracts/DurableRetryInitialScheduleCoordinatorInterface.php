<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialScheduleResolutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialSchedulingResult;

interface DurableRetryInitialScheduleCoordinatorInterface
{
    public function coordinate(
        DurableRetryInitialScheduleResolutionResult $resolution
    ): DurableRetryInitialSchedulingResult;
}
