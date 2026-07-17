<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\CustomerPanel\DTO;

final readonly class CustomerPurchaseItem
{
    public function __construct(
        public string $name,
        public ?string $image,
        public int $quantity,
        public string $unitPrice,
        public string $subtotal
    ) {
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'name_historical' => false,
            'image' => $this->image,
            'image_historical' => false,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'subtotal' => $this->subtotal,
        ];
    }
}
