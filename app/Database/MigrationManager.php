<?php

declare(strict_types=1);

namespace VeciAhorra\Database;

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Migrations\CreateInventoryTable;
use VeciAhorra\Database\Migrations\CreateOrdersTables;
use VeciAhorra\Database\Migrations\CreateReservationsTable;
use VeciAhorra\Database\Migrations\CreateCartItemsTable;

/**
 * Gestiona la versión instalada de la base de datos.
 */
final class MigrationManager
{
    /**
     * Nombre de la opción en WordPress.
     */
    private const OPTION_NAME = 'veciahorra_db_version';

    /**
     * Ejecuta las migraciones registradas en orden.
     */
    public static function migrate(): void
    {
        foreach (self::migrations() as $migration) {
            $migration->up();
        }
    }

    /**
     * Obtiene la versión instalada.
     */
    public static function installedVersion(): string
    {
        return (string) get_option(
            self::OPTION_NAME,
            '0.0.0'
        );
    }

    /**
     * Guarda la versión instalada.
     */
    public static function updateVersion(): void
    {
        update_option(
            self::OPTION_NAME,
            Config::SCHEMA_VERSION
        );
    }

    /**
     * Indica si la base de datos necesita actualización.
     */
    public static function needsMigration(): bool
    {
        return version_compare(
            self::installedVersion(),
            Config::SCHEMA_VERSION,
            '<'
        );
    }

    /**
     * @return list<CreateInventoryTable|CreateOrdersTables|CreateReservationsTable|CreateCartItemsTable>
     */
    private static function migrations(): array
    {
        return [
            new CreateInventoryTable(),
            new CreateOrdersTables(),
            new CreateReservationsTable(),
            new CreateCartItemsTable(),
        ];
    }
}
