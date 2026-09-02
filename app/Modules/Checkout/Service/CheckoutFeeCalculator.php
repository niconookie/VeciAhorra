<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Service;

use InvalidArgumentException;

final class CheckoutFeeCalculator
{
    public const MAX_TOTAL_CLP = 99999999;

    public function __construct(private ?CheckoutFeeConfiguration $configuration = null) {}

    /** @return array{product_subtotal:string,platform_fee:string,delivery_fee:string,total:string,currency:string,fulfillment_method:string,delivery_eligible:bool,delivery_minimum_subtotal:string,fee_policy_version:string} */
    public function calculate(int $productSubtotalClp, string $method, bool $deliveryEligible): array
    {
        if ($productSubtotalClp < 0 || $productSubtotalClp > self::MAX_TOTAL_CLP) {
            throw new InvalidArgumentException('El subtotal de productos no es valido.');
        }
        if (! in_array($method, [FulfillmentPolicy::PICKUP, FulfillmentPolicy::DELIVERY], true)) {
            throw new InvalidArgumentException('fulfillment_method no es valido.');
        }
        $config = ($this->configuration ?? new CheckoutFeeConfiguration())->current();
        $qualifies = $deliveryEligible && $productSubtotalClp >= $config['delivery_minimum_subtotal_clp'];
        if ($method === FulfillmentPolicy::DELIVERY && ! $qualifies) {
            throw new InvalidArgumentException('Delivery no esta autorizado para este Checkout.');
        }
        $deliveryFee = $method === FulfillmentPolicy::DELIVERY ? $config['delivery_fee_clp'] : 0;
        $total = $productSubtotalClp + $config['platform_fee_clp'] + $deliveryFee;
        if ($total > self::MAX_TOTAL_CLP) {
            throw new InvalidArgumentException('El total del checkout excede el limite monetario.');
        }

        return [
            'product_subtotal' => $this->money($productSubtotalClp),
            'platform_fee' => $this->money($config['platform_fee_clp']),
            'delivery_fee' => $this->money($deliveryFee),
            'total' => $this->money($total),
            'currency' => 'CLP',
            'fulfillment_method' => $method,
            'delivery_eligible' => $qualifies,
            'delivery_minimum_subtotal' => $this->money($config['delivery_minimum_subtotal_clp']),
            'fee_policy_version' => $config['policy_version'],
        ];
    }

    public static function clp(string $amount): int
    {
        if (preg_match('/^(0|[1-9][0-9]*)\.00$/D', $amount, $match) !== 1) {
            throw new InvalidArgumentException('El monto CLP no es canonico.');
        }
        $value = filter_var($match[1], FILTER_VALIDATE_INT);
        if (! is_int($value) || $value < 0 || $value > self::MAX_TOTAL_CLP) {
            throw new InvalidArgumentException('El monto CLP esta fuera de rango.');
        }
        return $value;
    }

    private function money(int $clp): string
    {
        return $clp . '.00';
    }
}
