<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\CustomerPanel\DTO;

final readonly class CustomerPurchaseOrder
{
    /** @param list<CustomerPurchaseItem> $items */
    public function __construct(
        public string $minimarketName,
        public string $subtotal,
        public array $items
    ) {
    }

    public function toArray(): array
    {
        return [
            'minimarket' => ['name' => $this->minimarketName, 'historical' => false],
            'subtotal' => $this->subtotal,
            'items' => array_map(static fn (CustomerPurchaseItem $item): array => $item->toArray(), $this->items),
        ];
    }
}
