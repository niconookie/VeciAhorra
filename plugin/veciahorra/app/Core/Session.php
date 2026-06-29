<?php

declare(strict_types=1);

namespace VeciAhorra\Core;

/**
 * Administrador de sesiones.
 */
final class Session
{
    /**
     * Inicia la sesión si aún no existe.
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Guarda un valor.
     */
    public static function put(
        string $key,
        mixed $value
    ): void {

        self::start();

        $_SESSION[$key] = $value;
    }

    /**
     * Obtiene un valor.
     */
    public static function get(
        string $key,
        mixed $default = null
    ): mixed {

        self::start();

        return $_SESSION[$key] ?? $default;
    }

    /**
     * Elimina un valor.
     */
    public static function forget(string $key): void
    {
        self::start();

        unset($_SESSION[$key]);
    }
}