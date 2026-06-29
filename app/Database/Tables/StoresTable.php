<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Tables;

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Contracts\TableInterface;

/**
 * Define la estructura de la tabla de minimarkets.
 */
final class StoresTable implements TableInterface
{
    /**
     * Nombre lógico de la tabla.
     */
    public function name(): string
    {
        return 'stores';
    }

    /**
     * Define la estructura.
     */
   public function define(TableBuilder $table): void
{
    $table

        ->id()

        /*
        |--------------------------------------------------------------------------
        | Información General
        |--------------------------------------------------------------------------
        */
        ->string('business_name', 150)

        ->string('legal_name', 150)

        ->string('owner_name', 150)

        ->string('rut', 20)

        /*
        |--------------------------------------------------------------------------
        | Contacto
        |--------------------------------------------------------------------------
        */
        ->string('email', 150)

        ->string('phone', 30)

        ->string('mobile', 30)
            ->nullable()

        /*
        |--------------------------------------------------------------------------
        | Dirección
        |--------------------------------------------------------------------------
        */
        ->string('address', 255)
            ->nullable()

        ->string('commune', 120)
            ->nullable()

        ->string('city', 120)
            ->nullable()

        ->string('region', 120)
            ->nullable()

        /*
        |--------------------------------------------------------------------------
        | Estado
        |--------------------------------------------------------------------------
        */
        ->string('status', 20)
            ->default('pending')

        ->string('onboarding_status', 30)
            ->default('draft')

        /*
        |--------------------------------------------------------------------------
        | Auditoría
        |--------------------------------------------------------------------------
        */
        ->datetime('approved_at')
            ->nullable()

        ->datetime('created_at')

        ->datetime('updated_at');
}
}