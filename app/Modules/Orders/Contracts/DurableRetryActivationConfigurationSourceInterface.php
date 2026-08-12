<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationConfiguration;

interface DurableRetryActivationConfigurationSourceInterface
{
    public function snapshot(): DurableRetryActivationConfiguration;
}
