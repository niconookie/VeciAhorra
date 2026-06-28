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

            ->string('business_name', 150)

            ->string('owner_name', 150)

            ->string('rut', 20)

            ->string('email', 150)

            ->string('phone', 30);
    }
}