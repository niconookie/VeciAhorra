<?php

declare(strict_types=1);

namespace VeciAhorra\Database;

use wpdb;

use VeciAhorra\Core\Config;

/**
 * VeciAhorra
 *
 * Clase de acceso a la base de datos.
 *
 * Centraliza el acceso a WordPress mediante wpdb.
 *
 * @package VeciAhorra
 * @since 1.0.0
 */
final class Database
{
    /**
     * Instancia de wpdb.
     *
     * @var wpdb
     */
    private wpdb $db;

    /**
     * Constructor.
     */
    public function __construct()
    {
        global $wpdb;

        $this->db = $wpdb;
    }

    /**
     * Obtiene la instancia de wpdb.
     *
     * @return wpdb
     */
    public function getConnection(): wpdb
    {
        return $this->db;
    }

    /**
     * Devuelve el prefijo de tablas.
     *
     * Ejemplo:
     * wp_
     * empresa_
     *
     * @return string
     */
    public function prefix(): string
    {
        return $this->db->prefix;
    }

    /**
     * Devuelve el nombre completo de una tabla VeciAhorra.
     *
     * Ejemplo:
     *
     * stores
     *
     * retorna:
     *
     * wp_va_stores
     *
     * @param string $table
     * @return string
     */
    public function table(string $table): string
{
    return $this->db->prefix . Config::TABLE_PREFIX . $table;
}
}