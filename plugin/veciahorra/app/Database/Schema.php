<?php

declare(strict_types=1);

namespace VeciAhorra\Database;

use VeciAhorra\Database\Contracts\TableInterface;
use VeciAhorra\Database\Tables\StoresTable;

/**
 * Registro central de todas las tablas del sistema.
 */
final class Schema
{
    /**
     * Retorna todas las tablas registradas.
     *
     * @return TableInterface[]
     */
    public static function tables(): array
    {
        return [

            new StoresTable(),

            // Próximamente...
            // new ProductsTable(),
            // new OrdersTable(),
            // new CouriersTable(),
            // new InventoryTable(),

        ];
    }
}