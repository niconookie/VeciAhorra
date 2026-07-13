<?php

declare(strict_types=1);

namespace VeciAhorra\Core;

/**
 * Configuración central del framework.
 *
 * Todas las constantes del proyecto deben definirse aquí.
 */
final class Config
{
    /**
     * Versión actual del plugin.
     */
    public const PLUGIN_VERSION = '0.3.0';

    /**
     * Versión actual del esquema de base de datos.
     */
    public const SCHEMA_VERSION = '0.16.0';

    /**
     * Alias temporal para compatibilidad con consumidores existentes.
     */
    public const VERSION = self::PLUGIN_VERSION;

    /**
     * Nombre del plugin.
     */
    public const NAME = 'VeciAhorra';

    /**
     * Text Domain.
     */
    public const TEXT_DOMAIN = 'veciahorra';

    /**
     * Prefijo de las tablas propias.
     */
    public const TABLE_PREFIX = 'va_';

    /**
     * Versión mínima de PHP.
     */
    public const MIN_PHP = '8.2';

    /**
     * Versión mínima de WordPress.
     */
    public const MIN_WP = '6.8';

    /**
     * Versión mínima de WooCommerce.
     */
    public const MIN_WC = '10.0';
}
