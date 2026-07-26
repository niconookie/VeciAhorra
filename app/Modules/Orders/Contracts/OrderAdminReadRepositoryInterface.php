<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\DTO\Admin\OrderAdminListQuery;

interface OrderAdminReadRepositoryInterface
{
    public function count(OrderAdminListQuery $query): int;

    /** @return list<array<string, mixed>> */
    public function paginate(OrderAdminListQuery $query): array;

    /** @param list<int> $orderIds
     * @return array<int, array<string, list<array<string, mixed>>>>
     */
    public function loadFacts(array $orderIds): array;

    /** @return array<string, mixed>|null */
    public function findBase(int $orderId): ?array;
}
