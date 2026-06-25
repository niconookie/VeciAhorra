<?php

declare(strict_types=1);

namespace VeciAhorra\Core;

/**
 * Clase Bootstrap.
 *
 * Punto de entrada del Framework VeciAhorra.
 *
 * @package VeciAhorra
 * @since 1.0.0
 */
final class Bootstrap
{
    /**
     * Inicializa la aplicación.
     */
    public static function boot(): void
    {
        self::load();
    }

    /**
     * Carga los servicios principales.
     */
    private static function load(): void
    {
        // Próximamente:
        // Application
        // Installer
        // Dashboard
        // Config
        // Logger
    }
}