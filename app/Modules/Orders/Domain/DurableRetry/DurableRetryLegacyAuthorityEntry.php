<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

final class DurableRetryLegacyAuthorityEntry
{
    public function __construct(
        private readonly DurableRetryAuthorityIdentity $identity,
        private readonly DurableRetryLegacyAuthorityResult $result
    ) {
    }

    public function identity(): DurableRetryAuthorityIdentity
    {
        return $this->identity;
    }

    public function result(): DurableRetryLegacyAuthorityResult
    {
        return $this->result;
    }
}
