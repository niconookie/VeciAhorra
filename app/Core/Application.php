<?php

declare(strict_types=1);

namespace VeciAhorra\Core;

use VeciAhorra\Admin\Menu;

/**
 * Clase principal de la aplicación.
 *
 * Responsable de iniciar todos los servicios del Framework.
 */
final class Application
{
    /**
     * Contenedor de dependencias.
     */
    private Container $container;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->container = new Container();
    }

    /**
     * Inicia la aplicación.
     */
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Menú del administrador
        |--------------------------------------------------------------------------
        */

        $this->container
            ->make(Menu::class)
            ->register();

        /*
        |--------------------------------------------------------------------------
        | Módulos
        |--------------------------------------------------------------------------
        */

        // Aquí iremos registrando los módulos.
    }

    /**
     * Devuelve el contenedor.
     */
    public function container(): Container
    {
        return $this->container;
    }
}