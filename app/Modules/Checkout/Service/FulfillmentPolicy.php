<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Service;

use InvalidArgumentException;

final class FulfillmentPolicy
{
    public const PICKUP = 'pickup';
    public const DELIVERY = 'delivery';

    public function minimumDeliveryAmount(): int
    {
        $value = apply_filters('veciahorra_minimum_delivery_amount', 8000);

        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && $value >= 0 && floor($value) === $value && $value <= PHP_INT_MAX) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9]\d*)$/D', $value) === 1 && (int) $value >= 0) {
            return (int) $value;
        }

        return 8000;
    }

    public function authorize(string $method, string $total): string
    {
        if (! in_array($method, [self::PICKUP, self::DELIVERY], true)) {
            throw new InvalidArgumentException('fulfillment_method no es valido.');
        }
        if (preg_match('/^(0|[1-9]\d*)\.00$/D', $total, $matches) !== 1) {
            throw new InvalidArgumentException('El total CLP no es valido.');
        }
        if ($method === self::DELIVERY && (int) $matches[1] < $this->minimumDeliveryAmount()) {
            throw new InvalidArgumentException('Delivery no esta autorizado para el total del Checkout.');
        }

        return $method;
    }
}
