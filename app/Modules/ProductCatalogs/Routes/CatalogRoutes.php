<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ProductCatalogs\Routes;

use VeciAhorra\Modules\ProductCatalogs\Controllers\CatalogController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

abstract class CatalogRoutes
{
    private const NAMESPACE = 'veciahorra/v1';

    public function __construct(
        private CatalogController $controller
    ) {
    }

    final public function register(): void
    {
        register_rest_route(
            self::NAMESPACE,
            $this->resource(),
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'index'],
                'permission_callback' => [
                    $this,
                    'canManageProducts',
                ],
            ]
        );
    }

    final public function index(
        WP_REST_Request $request
    ): WP_REST_Response {
        $result = $this->controller->index();
        $status = ($result['success'] ?? false) === true
            ? 200
            : 503;

        return new WP_REST_Response($result, $status);
    }

    /**
     * Reutiliza la política administrativa vigente de Products.
     */
    final public function canManageProducts(
        WP_REST_Request $request
    ): bool|WP_Error {
        return current_user_can('manage_options');
    }

    abstract protected function resource(): string;
}
