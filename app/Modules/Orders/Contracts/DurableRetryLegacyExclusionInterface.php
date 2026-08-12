<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentityCollection;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityBatchResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityResult;

interface DurableRetryLegacyExclusionInterface
{
    public function classify(
        DurableRetryAuthorityIdentity $identity
    ): DurableRetryLegacyAuthorityResult;

    public function classifyBatch(
        DurableRetryAuthorityIdentityCollection $identities
    ): DurableRetryLegacyAuthorityBatchResult;
}
