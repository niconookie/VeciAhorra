<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialAuthorityProductionResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest;

interface DurableRetryInitialAuthorityProducerInterface
{
    public function produceReconciliation(
        DurableRetryInitialTransferRequest $request
    ): DurableRetryInitialAuthorityProductionResult;
}
