<?php

declare(strict_types=1);

namespace VeciAhorra\Database;

use wpdb;

/**
 * Repositorio base.
 *
 * Todos los repositorios del sistema
 * heredarán de esta clase.
 */
abstract class Repository
{
    protected Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    /**
     * Devuelve la conexión de WordPress.
     */
    protected function db(): wpdb
    {
        return $this->database->getConnection();
    }

    /**
     * Devuelve el nombre físico de una tabla.
     */
    protected function table(string $table): string
    {
        return $this->database->table($table);
    }
}