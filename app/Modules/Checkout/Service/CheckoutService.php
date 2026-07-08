<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Service;

use VeciAhorra\Modules\Reservations\Service\ReservationService;

/**
 * Casos de uso sin efectos laterales para la foundation de Checkout.
 */
final class CheckoutService
{
    public function __construct(
        private CheckoutValidationService $validationService,
        private ReservationService $reservationService
    ) {
    }

    public function validate(array $payload): array
    {
        return $this->validationService->validate($payload);
    }

    public function initialize(array $payload): array
    {
        $validation = $this->validationService->validate($payload);

        if (! $validation['valid']) {
            return [
                ...$validation,
                'reservation_created' => false,
                'expires_at' => null,
                'reservations' => [],
            ];
        }

        $reservations = $this->reservationService->createForCheckout(
            $validation['items']
        );
        $expirationDates = array_column($reservations, 'expires_at');

        return [
            'valid' => true,
            'reservation_created' => true,
            'expires_at' => $expirationDates === []
                ? null
                : min($expirationDates),
            'reservations' => $reservations,
            'summary' => $validation['summary'],
        ];
    }
}
