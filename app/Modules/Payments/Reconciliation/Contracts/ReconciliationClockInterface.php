<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Contracts;

interface ReconciliationClockInterface
{
    public function now(): int;
}
