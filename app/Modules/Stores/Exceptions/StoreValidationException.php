<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Exceptions;

use InvalidArgumentException;
use LogicException;

final class StoreValidationException extends InvalidArgumentException
{
    private const FIELDS = [
        'business_name', 'legal_name', 'owner_name', 'rut', 'email', 'phone',
        'mobile', 'address', 'commune', 'city', 'region',
    ];

    private const CODES = ['required', 'invalid_email', 'too_long'];

    public function __construct(private array $errors)
    {
        if ($errors === []) {
            throw new LogicException('Mapa interno de validación Store vacío.');
        }
        foreach ($errors as $field => $code) {
            if (! is_string($field) || ! is_string($code)
                || ! in_array($field, self::FIELDS, true)
                || ! in_array($code, self::CODES, true)
            ) {
                throw new LogicException('Mapa interno de validación Store no válido.');
            }
        }
        $field = (string) array_key_first($errors);
        parent::__construct($this->legacyMessage($field, (string) $errors[$field]));
    }

    public function errors(): array
    {
        return $this->errors;
    }

    private function legacyMessage(string $field, string $code): string
    {
        $labels = [
            'business_name' => 'Nombre Comercial',
            'owner_name' => 'Propietario',
            'email' => 'Correo',
        ];
        if ($code === 'required') {
            return sprintf('El campo %s es obligatorio.', $labels[$field]);
        }
        if ($code === 'invalid_email') {
            return 'El correo electrónico no es válido.';
        }
        $limits = [
            'business_name' => 150, 'legal_name' => 150, 'owner_name' => 150,
            'rut' => 20, 'email' => 150, 'phone' => 30, 'mobile' => 30,
            'address' => 255, 'commune' => 120, 'city' => 120, 'region' => 120,
        ];
        return sprintf('El campo %s supera el máximo de %d caracteres.', $field, $limits[$field]);
    }
}
