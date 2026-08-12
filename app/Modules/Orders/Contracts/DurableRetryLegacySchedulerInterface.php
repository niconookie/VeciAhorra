<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Contracts;

interface DurableRetryLegacySchedulerInterface
{
    public function scheduleReconciliation(int $reconciliationId): bool;
}
