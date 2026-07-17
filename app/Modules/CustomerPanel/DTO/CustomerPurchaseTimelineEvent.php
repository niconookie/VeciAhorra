<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\CustomerPanel\DTO;

final readonly class CustomerPurchaseTimelineEvent
{
    public function __construct(public string $code, public string $label, public string $occurredAt)
    {
    }

    public function toArray(): array
    {
        return ['code' => $this->code, 'label' => $this->label, 'occurred_at' => $this->occurredAt];
    }
}
