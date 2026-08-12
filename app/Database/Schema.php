<?php

declare(strict_types=1);

namespace VeciAhorra\Database;

use VeciAhorra\Database\Contracts\TableInterface;
use VeciAhorra\Database\Tables\ProductsTable;
use VeciAhorra\Database\Tables\StoresTable;
use VeciAhorra\Database\Tables\CouriersTable;

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
            new ProductsTable(),
            new CouriersTable(),

            // Próximamente...
            // new OrdersTable(),
            // new InventoryTable(),

        ];
    }
}
