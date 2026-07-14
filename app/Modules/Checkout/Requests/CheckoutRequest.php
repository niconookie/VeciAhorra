<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Requests;

use InvalidArgumentException;

/**
 * Valida el request minimo de la foundation de Checkout.
 */
final class CheckoutRequest
{
    public function __construct(private array $input)
    {
    }

    /** @return array{fulfillment_method: string} */
    public function validated(): array
    {
        if (array_keys($this->input) !== ['fulfillment_method']) {
            throw new InvalidArgumentException(
                'El request solo admite fulfillment_method.'
            );
        }
        $method = $this->input['fulfillment_method'] ?? null;
        if (! is_string($method) || ! in_array($method, ['pickup', 'delivery'], true)) {
            throw new InvalidArgumentException('fulfillment_method no es valido.');
        }
        return ['fulfillment_method' => $method];
    }
}
