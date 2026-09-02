<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Service;

use VeciAhorra\Exceptions\ConflictException;

final class CheckoutRefundPolicy
{
    /** @return array{product_refund:int,platform_fee_refund:int,delivery_fee_refund:int,total_refund:int} */
    public function calculate(
        int $productSubtotal,
        int $platformFee,
        int $deliveryFee,
        int $alreadyProducts,
        int $alreadyPlatformFee,
        int $alreadyDeliveryFee,
        int $requestedProducts
    ): array {
        if ($requestedProducts <= 0 || $requestedProducts > $productSubtotal - $alreadyProducts) {
            throw new ConflictException('La devolucion excede el subtotal pendiente.', 'refund_exceeds_product_subtotal');
        }
        $completes = $alreadyProducts + $requestedProducts === $productSubtotal;
        $platformRefund = $completes && $alreadyPlatformFee === 0 ? $platformFee : 0;
        $deliveryRefund = $completes && $alreadyDeliveryFee === 0 ? $deliveryFee : 0;
        return [
            'product_refund' => $requestedProducts,
            'platform_fee_refund' => $platformRefund,
            'delivery_fee_refund' => $deliveryRefund,
            'total_refund' => $requestedProducts + $platformRefund + $deliveryRefund,
        ];
    }
}
