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

    /** @return array<string, mixed> */
    public function validated(): array
    {
        $allowed = ['fulfillment_method', 'delivery'];
        if (array_diff(array_keys($this->input), $allowed) !== []) {
            throw new InvalidArgumentException(
                'El request contiene campos no admitidos.'
            );
        }
        $method = $this->input['fulfillment_method'] ?? null;
        if (! is_string($method) || ! in_array($method, ['pickup', 'delivery'], true)) {
            throw new InvalidArgumentException('fulfillment_method no es valido.');
        }
        if ($method === 'pickup') {
            if (array_key_exists('delivery', $this->input)) {
                throw new InvalidArgumentException('pickup no admite datos de despacho.');
            }
            return ['fulfillment_method' => $method];
        }
        $delivery = $this->input['delivery'] ?? null;
        $deliveryKeys = ['recipient_name', 'contact_phone', 'address_line1', 'commune', 'reference', 'notes'];
        if (! is_array($delivery) || count($delivery) !== count($deliveryKeys)
            || array_diff(array_keys($delivery), $deliveryKeys) !== []) {
            throw new InvalidArgumentException('Los datos de despacho no son validos.');
        }
        $limits = ['recipient_name'=>200, 'contact_phone'=>30, 'address_line1'=>255, 'commune'=>120, 'reference'=>255];
        $clean = [];
        foreach ($limits as $field => $limit) {
            $value = $delivery[$field] ?? null;
            if (! is_string($value)) throw new InvalidArgumentException('Los datos de despacho no son validos.');
            $value = sanitize_text_field($value);
            if (in_array($field, ['recipient_name','contact_phone','address_line1','commune'], true) && $value === '') {
                throw new InvalidArgumentException('Los datos de despacho obligatorios estan incompletos.');
            }
            if (strlen($value) > $limit) throw new InvalidArgumentException('Un dato de despacho excede su longitud maxima.');
            $clean[$field] = $value === '' ? null : $value;
        }
        $notes = $delivery['notes'] ?? null;
        if (! is_string($notes)) throw new InvalidArgumentException('Las observaciones no son validas.');
        $clean['notes'] = ($notes = sanitize_textarea_field($notes)) === '' ? null : $notes;
        if (! preg_match('/^[+0-9][0-9\s()-]{6,19}$/D', (string) $clean['contact_phone'])) {
            throw new InvalidArgumentException('El telefono de despacho no es valido.');
        }
        return ['fulfillment_method' => $method, 'delivery' => $clean];
    }
}
