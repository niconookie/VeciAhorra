<?php

declare(strict_types=1);

namespace VeciAhorra\Core;

/**
 * Maneja mensajes temporales (Flash Messages).
 */
final class Flash
{
    /**
     * Guarda un mensaje de éxito.
     */
    public static function success(string $message): void
    {
        self::set('success', $message);
    }

    /**
     * Guarda un mensaje de error.
     */
    public static function error(string $message): void
    {
        self::set('error', $message);
    }

    /**
     * Guarda un mensaje.
     */
    private static function set(
        string $type,
        string $message
    ): void {

        Session::put(
    'veciahorra_flash',
    [
        'type' => $type,
        'message' => $message,
    ]
);
    }

    /**
     * Obtiene el mensaje almacenado.
     */
    public static function get(): ?array
{
    $flash = Session::get('veciahorra_flash');

    if ($flash === null) {
        return null;
    }

    Session::forget('veciahorra_flash');

    return $flash;
}
}