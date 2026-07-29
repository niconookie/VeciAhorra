<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Delivery\Completion\Contracts;

use VeciAhorra\Modules\Delivery\Completion\DTO\DeliveryCompletionResult;

interface DeliveryCompletionAttemptProcessorInterface
{
    public function process(
        int $businessCompletionId,
        string $owner,
        int $leaseSeconds = 600
    ): DeliveryCompletionResult;
}
