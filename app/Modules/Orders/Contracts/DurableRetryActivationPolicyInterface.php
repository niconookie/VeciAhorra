<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;

interface DurableRetryActivationPolicyInterface
{
    public function allowsInitialTransfer(
        DurableRetryAuthorityIdentity $identity
    ): bool;
}
