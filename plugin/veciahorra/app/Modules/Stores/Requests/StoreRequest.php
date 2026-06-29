<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Requests;

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
                'updated_at' => current_time('mysql'),
            ]
        );
    }

    /**
     * Campos comunes.
     */
    private function validatedFields(): array
    {
        return [

            'business_name' => sanitize_text_field($_POST['business_name'] ?? ''),

            'legal_name' => sanitize_text_field($_POST['legal_name'] ?? ''),

            'rut' => sanitize_text_field($_POST['rut'] ?? ''),

            'owner_name' => sanitize_text_field($_POST['owner_name'] ?? ''),

            'email' => sanitize_email($_POST['email'] ?? ''),

            'phone' => sanitize_text_field($_POST['phone'] ?? ''),

            'mobile' => sanitize_text_field($_POST['mobile'] ?? ''),

            'address' => sanitize_text_field($_POST['address'] ?? ''),

            'commune' => sanitize_text_field($_POST['commune'] ?? ''),

            'city' => sanitize_text_field($_POST['city'] ?? ''),

            'region' => sanitize_text_field($_POST['region'] ?? ''),
        ];
    }
}