<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Domain;

final class ProductReferenceInspection
{
    public const DELETABLE = 'deletable';

    public const RETIRE_REQUIRED = 'retire_required';

    public const DELETION_FORBIDDEN = 'deletion_forbidden';

    public const INCONSISTENT = 'inconsistent';

    public function __construct(
        private int $productId,
        private array $inventory,
        private array $cart,
        private array $reservations,
        private array $orderItems
    ) {
    }

    public function classification(): string
    {
        if ($this->orderItems['total'] > 0) {
            return self::DELETION_FORBIDDEN;
        }

        if ($this->inconsistencies() > 0) {
            return self::INCONSISTENT;
        }

        if ($this->reservations['total'] > 0) {
            return self::DELETION_FORBIDDEN;
        }

        if (
            $this->inventory['total'] > 0
            || $this->cart['total'] > 0
        ) {
            return self::RETIRE_REQUIRED;
        }

        return self::DELETABLE;
    }

    public function reasonCode(): ?string
    {
        if ($this->orderItems['total'] > 0) {
            return 'product_delete_historical_references';
        }

        if ($this->classification() === self::INCONSISTENT) {
            return 'product_reference_inconsistency';
        }

        if ($this->reservations['total'] > 0) {
            return 'product_delete_operational_references';
        }

        if (
            $this->inventory['total'] > 0
            || $this->cart['total'] > 0
        ) {
            return 'product_delete_requires_retirement';
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'classification' => $this->classification(),
            'reason_code' => $this->reasonCode(),
            'inventory' => $this->inventory,
            'cart' => $this->cart,
            'reservations' => $this->reservations,
            'order_items' => $this->orderItems,
        ];
    }

    private function inconsistencies(): int
    {
        return (int) $this->inventory['inconsistent']
            + (int) $this->cart['inconsistent']
            + (int) $this->reservations['inconsistent']
            + (int) $this->orderItems['inconsistent'];
    }
}
