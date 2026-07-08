<?php

declare(strict_types=1);

namespace VeciAhorra\Core;

use VeciAhorra\Admin\Menu;
use VeciAhorra\Modules\Inventory\Admin\InventoryPage;
use VeciAhorra\Modules\ProductCatalogs\Routes\BrandRoutes;
use VeciAhorra\Modules\ProductCatalogs\Routes\CategoryRoutes;
use VeciAhorra\Modules\ProductCatalogs\Routes\UnitRoutes;
use VeciAhorra\Modules\Inventory\Routes\InventoryRoutes;
use VeciAhorra\Modules\Cart\Routes\CartRoutes;
use VeciAhorra\Modules\Checkout\Routes\CheckoutRoutes;
use VeciAhorra\Modules\CustomerPanel\Routes\CustomerPanelRoutes;
use VeciAhorra\Modules\Orders\Routes\OrderRoutes;
use VeciAhorra\Modules\Payments\Routes\PaymentRoutes;
use VeciAhorra\Modules\Payments\Gateway\DummyPaymentGateway;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayInterface;
use VeciAhorra\Modules\Reservations\Routes\ReservationRoutes;
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
        $this->container->bind(
            PaymentGatewayInterface::class,
            static fn (): DummyPaymentGateway => new DummyPaymentGateway()
        );
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

        $inventoryPage = $this->container->make(
            InventoryPage::class
        );
        $inventoryPage->register();

        /*
        |--------------------------------------------------------------------------
        | Módulos
        |--------------------------------------------------------------------------
        */

        $productRoutes = $this->container->make(
            ProductRoutes::class
        );

        $cartRoutes = $this->container->make(
            CartRoutes::class
        );

        add_action(
            'rest_api_init',
            [$cartRoutes, 'register']
        );

        $checkoutRoutes = $this->container->make(
            CheckoutRoutes::class
        );

        add_action(
            'rest_api_init',
            [$checkoutRoutes, 'register']
        );

        $customerPanelRoutes = $this->container->make(
            CustomerPanelRoutes::class
        );

        add_action(
            'rest_api_init',
            [$customerPanelRoutes, 'register']
        );

        add_action(
            'rest_api_init',
            [$productRoutes, 'register']
        );

        $inventoryRoutes = $this->container->make(
            InventoryRoutes::class
        );

        add_action(
            'rest_api_init',
            [$inventoryRoutes, 'register']
        );

        $orderRoutes = $this->container->make(
            OrderRoutes::class
        );

        add_action(
            'rest_api_init',
            [$orderRoutes, 'register']
        );

        $paymentRoutes = $this->container->make(
            PaymentRoutes::class
        );

        add_action(
            'rest_api_init',
            [$paymentRoutes, 'register']
        );

        $reservationRoutes = $this->container->make(
            ReservationRoutes::class
        );

        add_action(
            'rest_api_init',
            [$reservationRoutes, 'register']
        );

        foreach (
            [
                CategoryRoutes::class,
                BrandRoutes::class,
                UnitRoutes::class,
            ] as $catalogRoutesClass
        ) {
            $catalogRoutes = $this->container->make(
                $catalogRoutesClass
            );

            add_action(
                'rest_api_init',
                [$catalogRoutes, 'register']
            );
        }
    }

    /**
     * Devuelve el contenedor.
     */
    public function container(): Container
    {
        return $this->container;
    }
}
