<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\BusinessCompletion\Contracts;

use VeciAhorra\Modules\Payments\BusinessCompletion\DTO\BusinessCompletionResult;

interface BusinessCompletionAttemptProcessorInterface
{
    public function process(
        int $reconciliationId,
        string $workerId,
        int $leaseSeconds = 30
    ): BusinessCompletionResult;
}
