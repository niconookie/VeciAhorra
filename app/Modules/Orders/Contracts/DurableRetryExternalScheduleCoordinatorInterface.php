<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryCoordinationResult;

interface DurableRetryExternalScheduleCoordinatorInterface
{
    public function coordinate(
        int $scheduleId,
        int $generation
    ): DurableRetryCoordinationResult;
}
