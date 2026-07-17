<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\CustomerPanel\DTO;

final readonly class CustomerPurchaseDetail
{
    /** @param list<CustomerPurchaseOrder> $orders @param list<CustomerPurchaseTimelineEvent> $timeline */
    public function __construct(
        public string $publicId,
        public string $createdAt,
        public CustomerPurchaseVisibleStatus $visibleStatus,
        public ?string $fulfillmentMethod,
        public CustomerPurchaseAmountSummary $total,
        public int $productQuantity,
        public int $minimarketCount,
        public array $orders,
        public ?array $payment,
        public array $delivery,
        public array $timeline
    ) {
    }

    public function toArray(): array
    {
        return [
            'checkout_public_id' => $this->publicId,
            'created_at' => $this->createdAt,
            'visible_status' => $this->visibleStatus->toArray(),
            'requires_review' => $this->visibleStatus->code === 'under_review',
            'fulfillment' => [
                'method' => $this->fulfillmentMethod,
                'label' => match ($this->fulfillmentMethod) {
                    'pickup' => 'Retiro',
                    'delivery' => 'Despacho',
                    default => 'Por confirmar',
                },
            ],
            'summary' => [
                'subtotal' => $this->total->amount,
                'total' => $this->total->amount,
                'currency' => $this->total->currency,
                'product_quantity' => $this->productQuantity,
                'line_count' => array_sum(array_map(
                    static fn (CustomerPurchaseOrder $order): int => count($order->items),
                    $this->orders
                )),
                'order_count' => count($this->orders),
                'minimarket_count' => $this->minimarketCount,
            ],
            'orders' => array_map(static fn (CustomerPurchaseOrder $order): array => $order->toArray(), $this->orders),
            'payment' => $this->payment,
            'delivery' => $this->delivery,
            'timeline' => array_map(static fn (CustomerPurchaseTimelineEvent $event): array => $event->toArray(), $this->timeline),
        ];
    }
}
