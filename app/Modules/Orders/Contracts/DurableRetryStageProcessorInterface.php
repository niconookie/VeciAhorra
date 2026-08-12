<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryExecutionContext;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingResult;

interface DurableRetryStageProcessorInterface
{
    public function stage(): string;

    public function process(
        DurableRetryExecutionContext $context
    ): DurableRetryProcessingResult;
}
