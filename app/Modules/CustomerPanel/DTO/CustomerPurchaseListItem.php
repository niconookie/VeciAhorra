<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\CustomerPanel\DTO;

final readonly class CustomerPurchaseListItem
{
    /** @param list<string> $minimarkets */
    public function __construct(
        public string $publicId,
        public string $createdAt,
        public CustomerPurchaseAmountSummary $total,
        public int $productQuantity,
        public int $orderCount,
        public int $minimarketCount,
        public array $minimarkets,
        public ?string $fulfillmentMethod,
        public CustomerPurchaseVisibleStatus $visibleStatus
    ) {
    }

    public function toArray(): array
    {
        return [
            'checkout_public_id' => $this->publicId,
            'created_at' => $this->createdAt,
            'total' => $this->total->toArray(),
            'product_quantity' => $this->productQuantity,
            'order_count' => $this->orderCount,
            'minimarket_count' => $this->minimarketCount,
            'minimarkets' => $this->minimarkets,
            'fulfillment_method' => $this->fulfillmentMethod,
            'visible_status' => $this->visibleStatus->toArray(),
            'requires_review' => $this->visibleStatus->code === 'under_review',
        ];
    }
}
