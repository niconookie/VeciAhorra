<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Reservations\Requests;

use InvalidArgumentException;
use VeciAhorra\Modules\Reservations\Service\ReservationService;

final class ReservationRequest
{
    public function __construct(private array $input)
    {
    }

    public function validated(): array
    {
        $data = [];

        foreach (['order_id', 'inventory_id', 'product_id', 'minimarket_id', 'quantity'] as $field) {
            $value = $this->input[$field] ?? null;

            if (is_string($value) && ctype_digit($value)) {
                $value = (int) $value;
            }

            if (! is_int($value) || $value <= 0) {
                throw new InvalidArgumentException(
                    "El campo {$field} debe ser un entero positivo."
                );
            }

            $data[$field] = $value;
        }

        $status = $this->input['status'] ?? 'active';

        if (! is_string($status) || ! in_array($status, ReservationService::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('El estado de la reserva no es valido.');
        }

        $data['status'] = $status;

        return $data;
    }
}
