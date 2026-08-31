<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Requests;

use InvalidArgumentException;
use VeciAhorra\Modules\Stores\Exceptions\StoreValidationException;

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
        $this->assertClosedCreatePayload();
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

        $errors = $this->requiredFieldErrors($fields)
            + $this->emailErrors($fields['email'])
            + $this->fieldLengthErrors($fields);
        if ($errors !== []) {
            throw new StoreValidationException($errors);
        }

        return $fields;
    }

    private function assertClosedCreatePayload(): void
    {
        $allowed = [
            'action', '_wpnonce', '_wp_http_referer', 'business_name',
            'legal_name', 'rut', 'owner_name', 'email', 'phone', 'mobile',
            'address', 'commune', 'city', 'region', 'submit',
        ];
        if (array_diff(array_keys($_POST), $allowed) !== []) {
            throw new InvalidArgumentException('La solicitud administrativa contiene datos no permitidos.');
        }
        foreach ($_POST as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException('La solicitud administrativa contiene datos no permitidos.');
            }
        }
        if (($_POST['action'] ?? null) !== 'veciahorra_store_create') {
            throw new InvalidArgumentException('La solicitud administrativa contiene datos no permitidos.');
        }
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
    private function requiredFieldErrors(array $fields): array
    {
        $requiredFields = ['business_name', 'owner_name', 'email'];

        $errors = [];
        foreach ($requiredFields as $field) {
            if (trim($fields[$field]) === '') {
                $errors[$field] = 'required';
            }
        }
        return $errors;
    }

    /**
     * Valida el correo electrónico.
     */
    private function emailErrors(string $email): array
    {
        if (is_email($email) === false) {
            return ['email' => 'invalid_email'];
        }
        return [];
    }

    /**
     * Valida las longitudes definidas por el esquema.
     */
    private function fieldLengthErrors(array $fields): array
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

        $errors = [];
        foreach ($limits as $field => $maximum) {
            $length = function_exists('mb_strlen')
                ? mb_strlen($fields[$field])
                : strlen($fields[$field]);

            if ($length > $maximum) {
                $errors[$field] = 'too_long';
            }
        }
        return $errors;
    }

}
