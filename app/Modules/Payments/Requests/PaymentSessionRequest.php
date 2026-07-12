<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Requests;

use InvalidArgumentException;
use VeciAhorra\Modules\Checkout\Models\Checkout;

final class PaymentSessionRequest
{
    public function __construct(private array $input)
    {
    }

    public function validated(): array
    {
        if (array_keys($this->input) !== ['checkout_id']) {
            throw new InvalidArgumentException(
                'El request solo admite checkout_id.'
            );
        }

        $checkoutId = $this->input['checkout_id'] ?? null;

        if (! is_string($checkoutId) || ! Checkout::validPublicId($checkoutId)) {
            throw new InvalidArgumentException('El checkout_id no es valido.');
        }

        return ['checkout_id' => $checkoutId];
    }
}
