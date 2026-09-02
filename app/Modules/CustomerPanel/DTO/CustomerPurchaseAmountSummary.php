<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\CustomerPanel\DTO;

final readonly class CustomerPurchaseAmountSummary
{
    public function __construct(
        public string $amount,
        public string $currency,
        public ?string $productSubtotal = null,
        public ?string $platformFee = null,
        public ?string $deliveryFee = null
    )
    {
    }

    public function toArray(): array
    {
        $data = ['amount' => $this->amount, 'currency' => $this->currency];
        if ($this->productSubtotal !== null) {
            $data += [
                'product_subtotal' => $this->productSubtotal,
                'platform_fee' => $this->platformFee,
                'delivery_fee' => $this->deliveryFee,
            ];
        }
        return $data;
    }
}
