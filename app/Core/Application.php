<?php

declare(strict_types=1);

namespace VeciAhorra\Core;

use VeciAhorra\Admin\Menu;
use VeciAhorra\Modules\Products\Admin\ProductsPage;
use VeciAhorra\Modules\Products\Routes\ProductRoutes;

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

        $productsPage = $this->container->make(
            ProductsPage::class
        );
        $productsPage->register();

        /*
        |--------------------------------------------------------------------------
        | Módulos
        |--------------------------------------------------------------------------
        */

        $productRoutes = $this->container->make(
            ProductRoutes::class
        );

        add_action(
            'rest_api_init',
            [$productRoutes, 'register']
        );
    }

    /**
     * Devuelve el contenedor.
     */
    public function container(): Container
    {
        return $this->container;
    }
}
