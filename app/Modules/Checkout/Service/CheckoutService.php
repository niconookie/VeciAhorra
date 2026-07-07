<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Service;

/**
 * Casos de uso sin efectos laterales para la foundation de Checkout.
 */
final class CheckoutService
{
    public function validate(array $payload): array
    {
        return [
            'valid' => true,
            'status' => 'checkout_valid',
            'message' => 'El checkout es valido a nivel basico.',
        ];
    }

    public function initialize(array $payload): array
    {
        return [
            'initialized' => true,
            'status' => 'checkout_initialized',
            'message' => 'Checkout inicializado sin crear pedidos.',
        ];
    }
}
