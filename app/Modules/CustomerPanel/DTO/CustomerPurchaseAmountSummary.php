<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\CustomerPanel\DTO;

final readonly class CustomerPurchaseAmountSummary
{
    public function __construct(public string $amount, public string $currency)
    {
    }

    public function toArray(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }
}
