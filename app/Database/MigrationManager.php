<?php

declare(strict_types=1);

namespace VeciAhorra\Database;

use VeciAhorra\Core\Config;

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
            Config::VERSION
        );
    }

    /**
     * Indica si la base de datos necesita actualización.
     */
    public static function needsMigration(): bool
    {
        return version_compare(
            self::installedVersion(),
            Config::VERSION,
            '<'
        );
    }
}