<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Service;

use InvalidArgumentException;
use VeciAhorra\Exceptions\ConflictException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRefundRepository;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;

final class CheckoutRefundService
{
    public function __construct(
        private ?CheckoutRepository $checkouts = null,
        private ?CheckoutRefundRepository $refunds = null
    ) {}

    /** Registra autoridad interna; no ejecuta una devolución bancaria. */
    public function record(int $checkoutId, string $idempotencyKey, string $productRefund): array
    {
        if ($checkoutId <= 0 || preg_match('/^[A-Za-z0-9:_-]{8,128}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('La solicitud de devolucion no es valida.');
        }
        $requestedClp = CheckoutFeeCalculator::clp($productRefund);
        if ($requestedClp <= 0) {
            throw new InvalidArgumentException('La devolucion de productos debe ser positiva.');
        }
        $checkouts = $this->checkouts ?? new CheckoutRepository();
        $refunds = $this->refunds ?? new CheckoutRefundRepository();

        return $checkouts->transaction(function () use ($checkouts, $refunds, $checkoutId, $idempotencyKey, $requestedClp): array {
            $checkout = $checkouts->findForUpdate($checkoutId);
            if ($checkout === null) {
                throw new RecordNotFoundException('El Checkout no existe.');
            }
            $existing = $refunds->findByKey($checkoutId, $idempotencyKey);
            if ($existing !== null) {
                if (CheckoutFeeCalculator::clp((string) $existing['product_refund']) !== $requestedClp) {
                    throw new ConflictException('La clave de devolucion fue usada con otro monto.', 'idempotency_conflict');
                }
                return [...$existing, 'reused' => true];
            }
            $historical = ! is_string($checkout['product_subtotal'] ?? null) || $checkout['product_subtotal'] === '';
            $productSubtotal = CheckoutFeeCalculator::clp($historical ? (string) $checkout['total_amount'] : (string) $checkout['product_subtotal']);
            $platformFee = $historical ? 0 : CheckoutFeeCalculator::clp((string) ($checkout['platform_fee'] ?? '0.00'));
            $deliveryFee = $historical ? 0 : CheckoutFeeCalculator::clp((string) ($checkout['delivery_fee'] ?? '0.00'));
            $totals = $refunds->totals($checkoutId);
            $alreadyProducts = CheckoutFeeCalculator::clp($totals['product_refund']);
            $decision = (new CheckoutRefundPolicy())->calculate(
                $productSubtotal,
                $platformFee,
                $deliveryFee,
                $alreadyProducts,
                CheckoutFeeCalculator::clp($totals['platform_fee_refund']),
                CheckoutFeeCalculator::clp($totals['delivery_fee_refund']),
                $requestedClp
            );
            $platformRefund = $decision['platform_fee_refund'];
            $deliveryRefund = $decision['delivery_fee_refund'];
            $data = [
                'checkout_id' => $checkoutId,
                'idempotency_key' => $idempotencyKey,
                'product_refund' => $requestedClp . '.00',
                'platform_fee_refund' => $platformRefund . '.00',
                'delivery_fee_refund' => $deliveryRefund . '.00',
                'total_refund' => $decision['total_refund'] . '.00',
                'status' => 'recorded',
                'created_at' => current_time('mysql'),
            ];
            $data['id'] = $refunds->create($data);
            return [...$data, 'reused' => false];
        });
    }
}
