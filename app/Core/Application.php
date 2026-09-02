<?php

declare(strict_types=1);

namespace VeciAhorra\Core;

use VeciAhorra\Admin\Menu;
use VeciAhorra\Modules\Inventory\Admin\InventoryPage;
use VeciAhorra\Modules\Inventory\Contracts\InventoryRepositoryInterface;
use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;
use VeciAhorra\Modules\Inventory\Services\InventoryReferenceValidator;
use VeciAhorra\Modules\Inventory\Services\InventoryService;
use VeciAhorra\Modules\ProductCatalogs\Routes\BrandRoutes;
use VeciAhorra\Modules\ProductCatalogs\Routes\CategoryRoutes;
use VeciAhorra\Modules\ProductCatalogs\Routes\UnitRoutes;
use VeciAhorra\Modules\ProductCatalogs\UnitTaxonomy;
use VeciAhorra\Modules\Inventory\Routes\InventoryRoutes;
use VeciAhorra\Modules\Cart\Routes\CartRoutes;
use VeciAhorra\Modules\Checkout\Routes\CheckoutRoutes;
use VeciAhorra\Modules\Checkout\Admin\CheckoutFeeSettingsPage;
use VeciAhorra\Modules\CustomerPanel\Routes\CustomerPanelRoutes;
use VeciAhorra\Modules\Delivery\Routes\DeliveryRoutes;
use VeciAhorra\Modules\Orders\Routes\OrderRoutes;
use VeciAhorra\Modules\Orders\Admin\OrdersPage;
use VeciAhorra\Modules\Orders\Contracts\OrderAdminReadRepositoryInterface;
use VeciAhorra\Modules\Orders\Repositories\OrderAdminReadRepository;
use VeciAhorra\Modules\Orders\Routes\OrdersAdminRoutes;
use VeciAhorra\Modules\Orders\Services\OrderAdminReadService;
use VeciAhorra\Modules\Orders\Services\OrderOperationalFactsAssembler;
use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalStateResolver;
use VeciAhorra\Modules\Payments\Routes\PaymentRoutes;
use VeciAhorra\Modules\Payments\Gateway\DummyPaymentGateway;
use VeciAhorra\Modules\Payments\Gateway\MockPaymentGateway;
use VeciAhorra\Modules\Payments\Gateway\PaymentConfirmationGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayConfiguration;
use VeciAhorra\Modules\Payments\Gateway\WebpayPaymentGateway;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnContextRepositoryInterface;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnGatewayResolverInterface;
use VeciAhorra\Modules\Payments\Contracts\OrderPaymentConfirmationInterface;
use VeciAhorra\Modules\Payments\Repository\TransientWebpayReturnContextRepository;
use VeciAhorra\Modules\Payments\Service\OrderPaymentConfirmationAdapter;
use VeciAhorra\Modules\Payments\WooCommerce\WebpayGatewayRegistration;
use VeciAhorra\Modules\Payments\WooCommerce\WooCommerceWebpayReturnGatewayResolver;
use VeciAhorra\Modules\Reservations\Routes\ReservationRoutes;
use VeciAhorra\Modules\Stores\Routes\StoreRoutes;
use VeciAhorra\Modules\ZonalAdmin\Routes\ZonalStoreRoutes;
use VeciAhorra\Modules\ZonalAdmin\Admin\ZonalStoresPage;
use VeciAhorra\Modules\Stores\Contracts\StoreTransitionRepositoryInterface;
use VeciAhorra\Modules\Stores\Repositories\StoreRepository;
use VeciAhorra\Modules\Stores\Services\StoreTransitionService;
use VeciAhorra\Modules\Products\Admin\ProductsPage;
use VeciAhorra\Modules\Products\Routes\ProductRoutes;
use VeciAhorra\Modules\Frontend\FrontendModule;
use VeciAhorra\Modules\Frontend\Support\PublicRouteResolver;
use VeciAhorra\Modules\Catalog\CatalogModule;
use VeciAhorra\Modules\Fulfillment\Orchestration\DurableCompletionOrchestration;
use VeciAhorra\Modules\Fulfillment\Orchestration\DurableCompletionScheduler;
use VeciAhorra\Modules\Payments\Orchestration\WebpayCreateRecovery;
use VeciAhorra\Modules\Payments\Orchestration\WebpayReturnRecovery;
use VeciAhorra\Modules\Checkout\Repository\CheckoutOrderRepository;
use VeciAhorra\Modules\Checkout\Repository\CheckoutRepository;
use VeciAhorra\Modules\Delivery\Completion\Repository\DeliveryCompletionRepository;
use VeciAhorra\Modules\Delivery\Completion\Service\DeliveryCompletionProcessor;
use VeciAhorra\Modules\Delivery\Repository\DeliveryRepository;
use VeciAhorra\Modules\Fulfillment\Completion\Repository\FulfillmentCompletionRepository;
use VeciAhorra\Modules\Fulfillment\Completion\Service\FulfillmentCompletionProcessor;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalScheduleCoordinatorInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryExternalSchedulerInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryProcessingPolicyInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryScheduleRepositoryInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryStageProcessorResolverInterface;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryProcessingPolicy;
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\ActionSchedulerDurableRetryAdapter;
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryActionCallback;
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryActionHookRegistrar;
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\DurableRetryProductionComposition;
use VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\WordPressOptionDurableRetryActivationConfigurationValueReader;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryScheduleRepository;
use VeciAhorra\Modules\Orders\Repositories\DurableRetryLegacyAuthorityRepository;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;
use VeciAhorra\Modules\Orders\Services\DurableRetryBusinessCompletionProcessor;
use VeciAhorra\Modules\Orders\Services\DurableRetryDeliveryCompletionProcessor;
use VeciAhorra\Modules\Orders\Services\DurableRetryExecutor;
use VeciAhorra\Modules\Orders\Services\DurableRetryExternalScheduleCoordinator;
use VeciAhorra\Modules\Orders\Services\DurableRetryFulfillmentProcessor;
use VeciAhorra\Modules\Orders\Services\DurableRetryProcessorRegistry;
use VeciAhorra\Modules\Orders\Services\DurableRetryReconciliationProcessor;
use VeciAhorra\Modules\Orders\Services\DurableRetryInitialProductionRouter;
use VeciAhorra\Modules\Payments\BusinessCompletion\Repository\BusinessCompletionRepository;
use VeciAhorra\Modules\Payments\BusinessCompletion\Service\BusinessCompletionProcessor;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationClaimRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentReconciliationRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\ValidatedFinancialResultRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Service\PaymentCompletionHandlerRegistry;
use VeciAhorra\Modules\Payments\Reconciliation\Service\PaymentReconciliationProcessor;
use VeciAhorra\Modules\Payments\Reconciliation\Service\PaymentReconciliationTechnicalEvaluator;
use VeciAhorra\Modules\Payments\Reconciliation\Service\WebpayReconciliationMaterializer;
use VeciAhorra\Modules\Payments\Reconciliation\Support\SystemReconciliationClock;
use VeciAhorra\Modules\Payments\Repository\PaymentRepository;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;
use VeciAhorra\Modules\Payments\Repository\WebpayReturnRepository;
use VeciAhorra\Modules\Payments\Service\WebpayReturnService;
use VeciAhorra\Modules\Orders\Services\PaymentTerminalOutcomeService;

/**
 * Clase principal de la aplicación.
 *
 * Responsable de iniciar todos los servicios del Framework.
 */
final class Application
{
    private static bool $registered = false;

    private ?DurableRetryInitialProductionRouter
        $durableRetryInitialProductionRouter = null;

    private \wpdb $database;

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
            InventoryRepositoryInterface::class,
            static fn (): InventoryRepository => new InventoryRepository()
        );
        $this->container->bind(
            InventoryService::class,
            fn (): InventoryService => new InventoryService(
                $this->container->make(InventoryRepositoryInterface::class),
                new InventoryReferenceValidator()
            )
        );
        $this->container->bind(
            StoreTransitionRepositoryInterface::class,
            static fn (): StoreRepository => new StoreRepository()
        );
        $this->container->bind(
            StoreTransitionService::class,
            fn (): StoreTransitionService => new StoreTransitionService(
                $this->container->make(StoreTransitionRepositoryInterface::class)
            )
        );
        $this->container->singleton(
            PublicRouteResolver::class,
            static fn (): PublicRouteResolver => new PublicRouteResolver()
        );
        $this->registerPaymentGateway();
        $this->container->singleton(
            WebpayReturnContextRepositoryInterface::class,
            static fn (): TransientWebpayReturnContextRepository =>
                new TransientWebpayReturnContextRepository()
        );
        $this->container->bind(
            WebpayReturnGatewayResolverInterface::class,
            fn (): WooCommerceWebpayReturnGatewayResolver =>
                new WooCommerceWebpayReturnGatewayResolver(
                    $this->container->make(WebpayReturnGatewayInterface::class)
                )
        );
        $this->container->bind(
            OrderPaymentConfirmationInterface::class,
            fn (): OrderPaymentConfirmationAdapter => $this->container->make(
                OrderPaymentConfirmationAdapter::class
            )
        );
        $this->container->bind(
            OrderAdminReadRepositoryInterface::class,
            static fn (): OrderAdminReadRepository => new OrderAdminReadRepository()
        );
        $this->container->bind(
            OrderAdminReadService::class,
            fn (): OrderAdminReadService => new OrderAdminReadService(
                $this->container->make(OrderAdminReadRepositoryInterface::class),
                new OrderOperationalFactsAssembler(),
                new OrderOperationalStateResolver(),
                gmdate('Y-m-d\TH:i:s\Z')
            )
        );
        $this->registerDurableRetryGraph();
        $this->container->singleton(
            WebpayReturnService::class,
            fn (): WebpayReturnService => new WebpayReturnService(
                $this->container->make(WebpayReturnGatewayInterface::class),
                new PaymentSessionRepository(),
                new WebpayReturnRepository(),
                $this->container->make(WebpayReconciliationMaterializer::class),
                $this->container->make(WebpayReturnContextRepositoryInterface::class),
                $this->container->make(WebpayReturnGatewayResolverInterface::class),
                new PaymentOriginContextRepository(),
                new PaymentTerminalOutcomeService()
            )
        );
    }

    private function registerDurableRetryGraph(): void
    {
        if ($this->durableRetryInitialProductionRouter !== null) {
            return;
        }

        $utcNow = static fn (): string => gmdate('Y-m-d H:i:s');

        $this->container->singleton(
            DurableRetryScheduleRepositoryInterface::class,
            static fn (): DurableRetryScheduleRepository =>
                new DurableRetryScheduleRepository()
        );
        $this->container->singleton(
            DurableRetryExternalSchedulerInterface::class,
            static fn (): ActionSchedulerDurableRetryAdapter =>
                new ActionSchedulerDurableRetryAdapter()
        );

        global $wpdb;

        if (! $wpdb instanceof \wpdb) {
            throw new \RuntimeException(
                'A WordPress database connection is required.'
            );
        }
        $database = $wpdb;
        $this->database = $database;
        $composition = new DurableRetryProductionComposition(
            $database,
            new WordPressOptionDurableRetryActivationConfigurationValueReader(),
            $this->container->make(DurableRetryExternalSchedulerInterface::class),
            new DurableCompletionScheduler(),
            $utcNow
        );
        $this->durableRetryInitialProductionRouter = $composition->router();
        $this->container->singleton(
            WebpayReconciliationMaterializer::class,
            fn (): WebpayReconciliationMaterializer =>
                new WebpayReconciliationMaterializer(
                    new ValidatedFinancialResultRepository(),
                    new PaymentReconciliationRepository(),
                    $this->durableRetryInitialProductionRouter
                )
        );
        $this->container->singleton(
            DurableRetryProcessingPolicyInterface::class,
            static fn (): DurableRetryProcessingPolicy =>
                new DurableRetryProcessingPolicy()
        );
        $this->container->singleton(
            DurableRetryExternalScheduleCoordinatorInterface::class,
            fn (): DurableRetryExternalScheduleCoordinator =>
                new DurableRetryExternalScheduleCoordinator(
                    $this->container->make(
                        DurableRetryScheduleRepositoryInterface::class
                    ),
                    $this->container->make(
                        DurableRetryExternalSchedulerInterface::class
                    ),
                    $utcNow
                )
        );
        $this->container->singleton(
            DurableRetryStageProcessorResolverInterface::class,
            static function (): DurableRetryProcessorRegistry {
                $claims = new PaymentReconciliationClaimRepository();
                $origins = new PaymentOriginContextRepository();
                $financialResults = new ValidatedFinancialResultRepository();
                $reconciliations = new PaymentReconciliationRepository(
                    $origins,
                    $financialResults
                );
                $reconciliationAttempts = new PaymentReconciliationProcessor(
                    $claims,
                    $reconciliations,
                    $origins,
                    $financialResults,
                    new PaymentReconciliationTechnicalEvaluator(),
                    new SystemReconciliationClock(),
                    30,
                    PaymentReconciliationClaimRepository::DEFAULT_LEASE_SECONDS,
                    new PaymentCompletionHandlerRegistry()
                );

                $businessCompletions = new BusinessCompletionRepository();
                $orders = new OrderRepository();
                $businessAttempts = new BusinessCompletionProcessor(
                    $businessCompletions,
                    $reconciliations,
                    new CheckoutRepository(),
                    new CheckoutOrderRepository(),
                    new PaymentSessionRepository(),
                    new PaymentRepository(),
                    $orders
                );

                $deliveryCompletions = new DeliveryCompletionRepository();
                $deliveryAttempts = new DeliveryCompletionProcessor(
                    $deliveryCompletions,
                    new DeliveryRepository(),
                    $orders
                );

                $fulfillmentCompletions =
                    new FulfillmentCompletionRepository();
                $fulfillmentAttempts = new FulfillmentCompletionProcessor(
                    $fulfillmentCompletions
                );

                return new DurableRetryProcessorRegistry([
                    new DurableRetryReconciliationProcessor(
                        $claims,
                        $reconciliationAttempts,
                        $reconciliations
                    ),
                    new DurableRetryBusinessCompletionProcessor(
                        $businessAttempts,
                        $businessCompletions
                    ),
                    new DurableRetryDeliveryCompletionProcessor(
                        $deliveryAttempts,
                        $deliveryCompletions
                    ),
                    new DurableRetryFulfillmentProcessor(
                        $fulfillmentAttempts,
                        $fulfillmentCompletions
                    ),
                ]);
            }
        );
        $this->container->singleton(
            DurableRetryExecutor::class,
            fn (): DurableRetryExecutor => new DurableRetryExecutor(
                $this->container->make(
                    DurableRetryScheduleRepositoryInterface::class
                ),
                $this->container->make(
                    DurableRetryProcessingPolicyInterface::class
                ),
                $this->container->make(
                    DurableRetryExternalScheduleCoordinatorInterface::class
                ),
                $this->container->make(
                    DurableRetryStageProcessorResolverInterface::class
                ),
                $utcNow
            )
        );
        $this->container->singleton(
            DurableRetryActionCallback::class,
            fn (): DurableRetryActionCallback =>
                new DurableRetryActionCallback(
                    $this->container->make(DurableRetryExecutor::class)
                )
        );
        $this->container->singleton(
            DurableRetryActionHookRegistrar::class,
            fn (): DurableRetryActionHookRegistrar =>
                new DurableRetryActionHookRegistrar(
                    $this->container->make(DurableRetryActionCallback::class)
                )
        );
    }

    private function registerPaymentGateway(): void
    {
        if (
            PaymentGatewayConfiguration::gateway()
                === PaymentGatewayConfiguration::GATEWAY_WEBPAY
        ) {
            $this->container->singleton(
                WebpayPaymentGateway::class,
                static fn (): WebpayPaymentGateway =>
                    new WebpayPaymentGateway(
                        PaymentGatewayConfiguration::webpay()
                    )
            );
            $this->container->bind(
                PaymentGatewayInterface::class,
                fn (): WebpayPaymentGateway => $this->container->make(
                    WebpayPaymentGateway::class
                )
            );
            $this->container->bind(
                PaymentConfirmationGatewayInterface::class,
                fn (): WebpayPaymentGateway => $this->container->make(
                    WebpayPaymentGateway::class
                )
            );
            $this->container->bind(
                WebpayReturnGatewayInterface::class,
                fn (): WebpayPaymentGateway => $this->container->make(
                    WebpayPaymentGateway::class
                )
            );

            return;
        }

        $this->container->bind(
            PaymentGatewayInterface::class,
            static fn (): MockPaymentGateway => new MockPaymentGateway()
        );
        $this->container->bind(
            PaymentConfirmationGatewayInterface::class,
            static fn (): DummyPaymentGateway => new DummyPaymentGateway()
        );
        $this->container->bind(
            WebpayReturnGatewayInterface::class,
            static fn (): DummyPaymentGateway => new DummyPaymentGateway()
        );
    }

    /**
     * Inicia la aplicación.
     */
    public function run(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        try {
        $unitTaxonomy = $this->container->make(UnitTaxonomy::class);
        add_action('init', [$unitTaxonomy, 'register'], 20);

        (new DurableCompletionOrchestration(
            new DurableRetryLegacyAuthorityRepository($this->database)
        ))->register();
        $this->container
            ->make(DurableRetryActionHookRegistrar::class)
            ->register();
        (new WebpayCreateRecovery())->register();
        (new WebpayReturnRecovery(
            $this->container->make(WebpayReconciliationMaterializer::class)
        ))->register();
        $this->container->make(WebpayGatewayRegistration::class)->register();

        $frontendModule = $this->container->make(
            FrontendModule::class
        );
        $frontendModule->register();

        $catalogModule = $this->container->make(
            CatalogModule::class
        );
        $catalogModule->register();

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

        $this->container->make(OrdersPage::class)->register();
        $this->container->make(ZonalStoresPage::class)->register();
        $this->container->make(CheckoutFeeSettingsPage::class)->register();

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

        $storeRoutes = $this->container->make(
            StoreRoutes::class
        );

        add_action(
            'rest_api_init',
            [$storeRoutes, 'register']
        );

        $zonalStoreRoutes = $this->container->make(ZonalStoreRoutes::class);
        add_action('rest_api_init', [$zonalStoreRoutes, 'register']);

        $orderRoutes = $this->container->make(
            OrderRoutes::class
        );

        add_action(
            'rest_api_init',
            [$orderRoutes, 'register']
        );

        $ordersAdminRoutes = $this->container->make(
            OrdersAdminRoutes::class
        );
        add_action(
            'rest_api_init',
            [$ordersAdminRoutes, 'register']
        );

        $paymentRoutes = $this->container->make(
            PaymentRoutes::class
        );

        add_action(
            'rest_api_init',
            [$paymentRoutes, 'register']
        );

        $deliveryRoutes = $this->container->make(
            DeliveryRoutes::class
        );

        add_action(
            'rest_api_init',
            [$deliveryRoutes, 'register']
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
        } catch (\Throwable $throwable) {
            self::$registered = false;
            throw $throwable;
        }
    }

    /**
     * Devuelve el contenedor.
     */
    public function container(): Container
    {
        return $this->container;
    }

    public function durableRetryExecutor(): DurableRetryExecutor
    {
        return $this->container->make(DurableRetryExecutor::class);
    }

    public function durableRetryCallback(): DurableRetryActionCallback
    {
        return $this->container->make(DurableRetryActionCallback::class);
    }

    public function durableRetryWebpayMaterializer(): WebpayReconciliationMaterializer
    {
        return $this->container->make(WebpayReconciliationMaterializer::class);
    }
}
