<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Requests;

use InvalidArgumentException;

final class PaymentConfirmationRequest
{
    public function __construct(private array $input)
    {
    }

    /** @return array{provider: string, provider_reference: string} */
    public function validated(): array
    {
        $provider = $this->text('provider', 50);
        $reference = $this->text('provider_reference', 191);

        if (! preg_match('/^[A-Za-z0-9_-]+$/', $reference)) {
            throw new InvalidArgumentException(
                'El campo provider_reference no es valido.'
            );
        }

        return [
            'provider' => strtolower($provider),
            'provider_reference' => $reference,
        ];
    }

    private function text(string $field, int $maximum): string
    {
        $value = $this->input[$field] ?? null;
        $value = is_string($value) ? trim(wp_unslash($value)) : '';

        if ($value === '' || strlen($value) > $maximum) {
            throw new InvalidArgumentException(
                "El campo {$field} es obligatorio y debe ser texto valido."
            );
        }

        return $value;
    }
}
