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
        int $requestedProducts,
        ?int $originalTotal = null,
        ?int $accumulatedTotal = null
    ): array {
        foreach ([$productSubtotal, $platformFee, $deliveryFee, $alreadyProducts, $alreadyPlatformFee, $alreadyDeliveryFee, $originalTotal ?? 0, $accumulatedTotal ?? 0] as $value) {
            if ($value < 0) {
                throw new ConflictException('Importe negativo.', 'refund_negative_amount');
            }
        }
        $originalSum = $this->sum([$productSubtotal, $platformFee, $deliveryFee]);
        $accumulatedSum = $this->sum([$alreadyProducts, $alreadyPlatformFee, $alreadyDeliveryFee]);
        // Optional totals preserve existing callers; financial operations pass both frozen totals.
        $originalTotal ??= $originalSum;
        $accumulatedTotal ??= $accumulatedSum;
        if ($alreadyProducts > $productSubtotal) {
            throw new ConflictException('Acumulado de productos invalido.', 'refund_product_limit');
        }
        if ($alreadyPlatformFee > $platformFee) {
            throw new ConflictException('Acumulado de plataforma invalido.', 'refund_platform_limit');
        }
        if ($alreadyDeliveryFee > $deliveryFee) {
            throw new ConflictException('Acumulado de despacho invalido.', 'refund_delivery_limit');
        }
        if ($accumulatedTotal > $originalTotal) {
            throw new ConflictException('Acumulado total invalido.', 'refund_total_limit');
        }
        if ($originalSum !== $originalTotal || $accumulatedSum !== $accumulatedTotal) {
            throw new ConflictException('Los componentes no coinciden con el total.', 'refund_inconsistent_components');
        }
        $remainingProduct = $productSubtotal - $alreadyProducts;
        $remainingPlatform = $platformFee - $alreadyPlatformFee;
        $remainingDelivery = $deliveryFee - $alreadyDeliveryFee;
        $remainingTotal = $originalTotal - $accumulatedTotal;
        if ($requestedProducts <= 0 || $requestedProducts > $remainingProduct) {
            throw new ConflictException('La devolucion excede el subtotal pendiente.', 'refund_exceeds_product_subtotal');
        }
        $completes = $requestedProducts === $remainingProduct;
        $platformRefund = $completes ? $remainingPlatform : 0;
        $deliveryRefund = $completes ? $remainingDelivery : 0;
        $totalRefund = $this->sum([$requestedProducts, $platformRefund, $deliveryRefund]);
        if ($platformRefund > $remainingPlatform || $deliveryRefund > $remainingDelivery || $totalRefund > $remainingTotal
            || ($completes && $totalRefund !== $remainingTotal)) {
            throw new ConflictException('Resultado fuera del remanente.', 'refund_result_limit');
        }
        return [
            'product_refund' => $requestedProducts,
            'platform_fee_refund' => $platformRefund,
            'delivery_fee_refund' => $deliveryRefund,
            'total_refund' => $totalRefund,
        ];
    }

    /** Addition is checked before PHP can promote an overflowing integer. */
    private function sum(array $values): int
    {
        $sum = 0;
        foreach ($values as $value) {
            if ($value > PHP_INT_MAX - $sum) {
                throw new ConflictException('Importe fuera del rango entero.', 'refund_integer_overflow');
            }
            $sum += $value;
        }
        return $sum;
    }
}
