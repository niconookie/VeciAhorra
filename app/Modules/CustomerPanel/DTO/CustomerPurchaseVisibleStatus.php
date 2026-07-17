<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\CustomerPanel\DTO;

final readonly class CustomerPurchaseVisibleStatus
{
    public function __construct(
        public string $code,
        public string $label,
        public string $message
    ) {
    }

    public function toArray(): array
    {
        return ['code' => $this->code, 'label' => $this->label, 'message' => $this->message];
    }
}
