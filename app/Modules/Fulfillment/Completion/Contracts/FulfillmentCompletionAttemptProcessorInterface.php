<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Fulfillment\Completion\Contracts;

use VeciAhorra\Modules\Fulfillment\Completion\DTO\FulfillmentCompletionResult;

interface FulfillmentCompletionAttemptProcessorInterface
{
    public function process(
        int $businessCompletionId,
        string $owner,
        int $leaseSeconds = 600
    ): FulfillmentCompletionResult;
}
