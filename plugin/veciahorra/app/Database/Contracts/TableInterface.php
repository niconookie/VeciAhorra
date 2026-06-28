<?php

declare(strict_types=1);

namespace VeciAhorra\Database\Contracts;

use VeciAhorra\Database\Builder\TableBuilder;

/**
 * Contrato que deben implementar todas las tablas.
 */
interface TableInterface
{
    /**
     * Nombre lógico de la tabla.
     */
    public function name(): string;

    /**
     * Define la estructura de la tabla.
     */
    public function define(TableBuilder $table): void;
}