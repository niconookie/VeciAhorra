<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\WooCommerce\Contracts;

interface WooCommerceOrderRepositoryInterface
{
    public function find(int $orderId): ?object;
}
