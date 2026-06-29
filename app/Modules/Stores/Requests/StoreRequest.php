<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Requests;

use InvalidArgumentException;

/**
 * Maneja y valida los datos del formulario
 * de minimarkets.
 */
final class StoreRequest
{
    /**
     * Datos para crear un minimarket.
     */
    public function validatedForCreate(): array
    {
        check_admin_referer('veciahorra_store');

        return array_merge(
            $this->validatedFields(),
            [
                'status' => 'pending',
                'onboarding_status' => 'draft',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]
        );
    }

    /**
     * Datos para actualizar un minimarket.
     */
    public function validatedForUpdate(): array
    {
        check_admin_referer('veciahorra_store');

        return array_merge(
            $this->validatedFields(),
            [
                'status' => $this->validatedStatus(),
                'updated_at' => current_time('mysql'),
            ]
        );
    }

    /**
     * Campos comunes.
     */
    private function validatedFields(): array
    {
        $fields = [

            'business_name' => sanitize_text_field(
                $this->input('business_name')
            ),

            'legal_name' => sanitize_text_field(
                $this->input('legal_name')
            ),

            'rut' => sanitize_text_field(
                $this->input('rut')
            ),

            'owner_name' => sanitize_text_field(
                $this->input('owner_name')
            ),

            'email' => sanitize_email(
                $this->input('email')
            ),

            'phone' => sanitize_text_field(
                $this->input('phone')
            ),

            'mobile' => sanitize_text_field(
                $this->input('mobile')
            ),

            'address' => sanitize_text_field(
                $this->input('address')
            ),

            'commune' => sanitize_text_field(
                $this->input('commune')
            ),

            'city' => sanitize_text_field(
                $this->input('city')
            ),

            'region' => sanitize_text_field(
                $this->input('region')
            ),
        ];

        $this->validateRequiredFields($fields);
        $this->validateEmail($fields['email']);
        $this->validateFieldLengths($fields);

        return $fields;
    }

    /**
     * Obtiene un valor escalar normalizado del formulario.
     */
    private function input(string $field): string
    {
        $value = $_POST[$field] ?? '';

        if (! is_string($value)) {
            return '';
        }

        return wp_unslash($value);
    }

    /**
     * Valida los campos obligatorios.
     */
    private function validateRequiredFields(array $fields): void
    {
        $requiredFields = [
            'business_name' => 'Nombre Comercial',
            'owner_name' => 'Propietario',
            'email' => 'Correo',
        ];

        foreach ($requiredFields as $field => $label) {
            if (trim($fields[$field]) === '') {
                throw new InvalidArgumentException(
                    sprintf(
                        'El campo %s es obligatorio.',
                        $label
                    )
                );
            }
        }
    }

    /**
     * Valida el correo electrónico.
     */
    private function validateEmail(string $email): void
    {
        if (is_email($email) === false) {
            throw new InvalidArgumentException(
                'El correo electrónico no es válido.'
            );
        }
    }

    /**
     * Valida las longitudes definidas por el esquema.
     */
    private function validateFieldLengths(array $fields): void
    {
        $limits = [
            'business_name' => 150,
            'legal_name' => 150,
            'owner_name' => 150,
            'rut' => 20,
            'email' => 150,
            'phone' => 30,
            'mobile' => 30,
            'address' => 255,
            'commune' => 120,
            'city' => 120,
            'region' => 120,
        ];

        foreach ($limits as $field => $maximum) {
            $length = function_exists('mb_strlen')
                ? mb_strlen($fields[$field])
                : strlen($fields[$field]);

            if ($length > $maximum) {
                throw new InvalidArgumentException(
                    sprintf(
                        'El campo %s supera el máximo de %d caracteres.',
                        $field,
                        $maximum
                    )
                );
            }
        }
    }

    /**
     * Valida el estado del minimarket.
     */
    private function validatedStatus(): string
    {
        $status = sanitize_key(
            $this->input('status')
        );

        $allowedStatuses = [
            'pending',
            'active',
            'inactive',
            'rejected',
        ];

        if (! in_array($status, $allowedStatuses, true)) {
            throw new InvalidArgumentException('El estado del minimarket no es válido.');
        }

        return $status;
    }
}
