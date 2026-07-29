<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Contracts;

interface DurableRetryStageProcessorResolverInterface
{
    public function resolve(
        string $stage
    ): DurableRetryStageProcessorInterface;
}
