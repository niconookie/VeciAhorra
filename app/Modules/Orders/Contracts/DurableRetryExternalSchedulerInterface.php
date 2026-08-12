<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExternalScheduleResult;

interface DurableRetryExternalSchedulerInterface
{
    public function schedule(
        string $hook,
        array $arguments,
        string $group,
        string $scheduledFor
    ): DurableRetryExternalScheduleResult;

    public function findPending(
        string $hook,
        array $arguments,
        string $group
    ): DurableRetryExternalScheduleResult;

    public function cancel(
        int $scheduledActionId,
        string $hook,
        array $arguments,
        string $group
    ): DurableRetryExternalScheduleResult;
}
