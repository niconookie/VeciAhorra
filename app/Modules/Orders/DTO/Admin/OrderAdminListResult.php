<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\DTO\Admin;

final readonly class OrderAdminListResult
{
    /** @param list<OrderAdminListItem> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage
    ) {
    }

    public function toArray(): array
    {
        return [
            'items' => array_map(
                static fn (OrderAdminListItem $item): array => $item->toArray(),
                $this->items
            ),
            'pagination' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'total_pages' => $this->total === 0 ? 0 : (int) ceil($this->total / $this->perPage),
            ],
        ];
    }
}
