<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Service;

use VeciAhorra\Modules\Orders\Repositories\OrderRepository;
use VeciAhorra\Modules\Payments\Contracts\OrderPaymentConfirmationInterface;

final class OrderPaymentConfirmationAdapter implements
    OrderPaymentConfirmationInterface
{
    public function __construct(private OrderRepository $orders)
    {
    }

    public function lockForPayment(array $orderIds): array
    {
        $ids = array_values(array_unique($orderIds));
        sort($ids, SORT_NUMERIC);

        if ($ids === [] || $ids !== $orderIds) {
            throw new \InvalidArgumentException(
                'Las Orders deben ser unicas y estar ordenadas.'
            );
        }

        return $this->orders->findManyForUpdate($ids);
    }

    public function confirmPaid(array $orderIds, string $updatedAt): int
    {
        return $this->orders->markPaid($orderIds, $updatedAt);
    }

    public function readForRecovery(array $orderIds): array
    {
        return $this->orders->findMany($orderIds);
    }
}
