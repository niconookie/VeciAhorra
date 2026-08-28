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
    public static function start(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        if (session_status() === PHP_SESSION_NONE) {
            return @session_start() && session_status() === PHP_SESSION_ACTIVE;
        }

        return false;
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

    public static function putVerifiedAndClose(string $key, mixed $value): bool
    {
        if (! self::start()) {
            return false;
        }

        $sessionId = session_id();
        if ($sessionId === '') {
            @session_write_close();
            return false;
        }

        $_SESSION[$key] = $value;
        $writtenInMemory = array_key_exists($key, $_SESSION) && $_SESSION[$key] === $value;
        @session_write_close();

        if (! $writtenInMemory || session_status() === PHP_SESSION_ACTIVE) {
            return false;
        }

        // Force the verification read to come from the configured session handler.
        $_SESSION = [];
        if (session_id() !== $sessionId) {
            session_id($sessionId);
        }

        if (! @session_start() || session_status() !== PHP_SESSION_ACTIVE) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_write_close();
            }
            return false;
        }

        $persisted = array_key_exists($key, $_SESSION) && $_SESSION[$key] === $value;
        $verificationClosed = @session_write_close();

        return $persisted && $verificationClosed && session_status() === PHP_SESSION_NONE;
    }
}
