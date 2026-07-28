<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionResult;

interface DurableRetryExecutorInterface
{
    public function execute(
        string $hook,
        int $scheduleId,
        int $generation
    ): DurableRetryExecutionResult;
}
