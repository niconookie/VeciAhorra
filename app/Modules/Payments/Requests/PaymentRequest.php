<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Requests;

use InvalidArgumentException;

final class PaymentRequest
{
    /** @var list<string> */
    private array $errors = [];

    public function __construct(private array $input)
    {
    }

    /**
     * @return array{
     *     customer_id: int,
     *     amount: string,
     *     currency: string,
     *     provider: ?string,
     *     order_ids: list<int>
     * }
     */
    public function validated(): array
    {
        $this->errors = [];
        $data = [
            'customer_id' => $this->positiveInteger('customer_id'),
            'amount' => $this->amount(),
            'currency' => $this->currency(),
            'provider' => $this->provider(),
            'order_ids' => $this->orderIds(),
        ];

        if ($this->errors !== []) {
            throw new InvalidArgumentException(implode(' ', $this->errors));
        }

        return $data;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    private function positiveInteger(string $field): int
    {
        if (! array_key_exists($field, $this->input)) {
            $this->errors[] = "El campo {$field} es obligatorio.";

            return 0;
        }

        $value = $this->value($this->input[$field]);

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (
            is_string($value)
            && ctype_digit(trim($value))
            && (int) $value > 0
            && (string) (int) $value === ltrim(trim($value), '0')
        ) {
            return (int) $value;
        }

        $this->errors[] = "El campo {$field} debe ser un entero positivo.";

        return 0;
    }

    private function amount(): string
    {
        if (! array_key_exists('amount', $this->input)) {
            $this->errors[] = 'El campo amount es obligatorio.';

            return '0.00';
        }

        $value = $this->value($this->input['amount']);

        if (is_int($value)) {
            $raw = (string) $value;
        } elseif (is_float($value) && is_finite($value)) {
            $raw = rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');
        } elseif (is_string($value)) {
            $raw = trim($value);
        } else {
            $raw = '';
        }

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $raw)) {
            $this->errors[] = 'El campo amount debe ser un decimal positivo.';

            return '0.00';
        }

        [$whole, $decimal] = array_pad(explode('.', $raw, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $decimal = str_pad($decimal, 2, '0');

        if (
            strlen($whole) > 8
            || ((int) $whole === 0 && (int) $decimal === 0)
        ) {
            $this->errors[] = 'El campo amount debe ser mayor a 0 y valido.';

            return '0.00';
        }

        return $whole . '.' . $decimal;
    }

    private function currency(): string
    {
        $value = $this->value($this->input['currency'] ?? 'CLP');
        $currency = is_string($value) ? strtoupper(trim($value)) : '';

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $this->errors[] = 'El campo currency debe usar tres letras.';

            return 'CLP';
        }

        return $currency;
    }

    private function provider(): ?string
    {
        if (! array_key_exists('provider', $this->input)) {
            return null;
        }

        $value = $this->value($this->input['provider']);

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (! is_string($value) || strlen(trim($value)) > 50) {
            $this->errors[] = 'El campo provider debe ser texto valido.';

            return null;
        }

        return strtolower(trim($value));
    }

    /** @return list<int> */
    private function orderIds(): array
    {
        $value = $this->input['order_ids'] ?? null;

        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            $this->errors[] = 'El campo order_ids debe ser una lista no vacia.';

            return [];
        }

        $ids = [];

        foreach ($value as $index => $orderId) {
            $validator = new self(['order_id' => $orderId]);
            $id = $validator->positiveInteger('order_id');

            if ($validator->errors !== []) {
                $this->errors[] = sprintf(
                    'El elemento order_ids[%d] debe ser un entero positivo.',
                    $index
                );

                continue;
            }

            if (in_array($id, $ids, true)) {
                $this->errors[] = 'El campo order_ids no admite duplicados.';

                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }

    private function value(mixed $value): mixed
    {
        return is_string($value) ? wp_unslash($value) : $value;
    }
}
