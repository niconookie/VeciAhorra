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
        return (new CheckoutFeeConfiguration())->current()['delivery_minimum_subtotal_clp'];
    }

    public function authorize(string $method, string $productSubtotal): string
    {
        if (! in_array($method, [self::PICKUP, self::DELIVERY], true)) {
            throw new InvalidArgumentException('fulfillment_method no es valido.');
        }
        if (preg_match('/^(0|[1-9]\d*)\.00$/D', $productSubtotal, $matches) !== 1) {
            throw new InvalidArgumentException('El subtotal CLP no es valido.');
        }
        if ($method === self::DELIVERY && (int) $matches[1] < $this->minimumDeliveryAmount()) {
            throw new InvalidArgumentException('Delivery no esta autorizado para el total del Checkout.');
        }

        return $method;
    }
}
