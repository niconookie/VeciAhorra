<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Routes;

use VeciAhorra\Modules\Orders\Controllers\OrderController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Adaptador HTTP REST del modulo Orders.
 */
final class OrderRoutes
{
    private const NAMESPACE = 'veciahorra/v1';

    private const RESOURCE = '/orders';

    public function __construct(
        private OrderController $controller
    ) {
    }

    public function register(): void
    {
        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE,
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'index'],
                    'permission_callback' => [$this, 'canManageOrders'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'store'],
                    'permission_callback' => [$this, 'canManageOrders'],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/(?P<id>\d+)',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'show'],
                'permission_callback' => [$this, 'canManageOrders'],
            ]
        );
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return $this->toResponse(
            $this->controller->index($request->get_query_params())
        );
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return $this->toResponse(
            $this->controller->show(
                (int) ($request->get_url_params()['id'] ?? 0)
            )
        );
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        return $this->toResponse(
            $this->controller->store(
                (array) $request->get_json_params()
            ),
            201
        );
    }

    public function canManageOrders(
        WP_REST_Request $request
    ): bool|WP_Error {
        return current_user_can('manage_options');
    }

    private function toResponse(
        array $result,
        int $successStatus = 200
    ): WP_REST_Response {
        $status = ($result['success'] ?? false) === true
            ? $successStatus
            : match ($result['error']['code'] ?? '') {
                'validation_error' => 422,
                'order_not_found' => 404,
                default => 500,
            };

        return new WP_REST_Response($result, $status);
    }
}
