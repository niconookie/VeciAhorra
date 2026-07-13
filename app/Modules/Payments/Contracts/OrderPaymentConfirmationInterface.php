<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Contracts;

interface OrderPaymentConfirmationInterface
{
    public function lockForPayment(array $orderIds): array;

    public function readForRecovery(array $orderIds): array;

    public function confirmPaid(array $orderIds, string $updatedAt): int;
}
