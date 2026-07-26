<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\DTO\Admin;

final readonly class OrderAdminDetail
{
    public function __construct(private array $data)
    {
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
