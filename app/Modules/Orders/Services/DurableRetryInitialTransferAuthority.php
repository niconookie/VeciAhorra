<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Services;

use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialTransferAuthorityInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialTransferRepositoryInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferResult;

final class DurableRetryInitialTransferAuthority
    implements DurableRetryInitialTransferAuthorityInterface
{
    public function __construct(
        private readonly DurableRetryInitialTransferRepositoryInterface $repository
    ) {
    }

    public function transferReconciliation(
        DurableRetryInitialTransferRequest $request
    ): DurableRetryInitialTransferResult {
        return $this->repository->transferReconciliation($request);
    }
}
