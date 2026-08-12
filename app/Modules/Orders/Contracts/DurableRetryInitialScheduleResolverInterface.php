<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialAuthorityProductionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialScheduleResolutionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest;

interface DurableRetryInitialScheduleResolverInterface
{
    public function resolve(
        DurableRetryInitialTransferRequest $request,
        DurableRetryInitialAuthorityProductionResult $authority
    ): DurableRetryInitialScheduleResolutionResult;
}
