<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Delivery\Routes;

use VeciAhorra\Modules\Delivery\Controller\DeliveryController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Adaptador HTTP REST del modulo Delivery.
 */
final class DeliveryRoutes
{
    private const NAMESPACE = 'veciahorra/v1';

    private const RESOURCE = '/deliveries';

    public function __construct(
        private DeliveryController $controller
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
                    'permission_callback' => [$this, 'canManageDeliveries'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'store'],
                    'permission_callback' => [$this, 'canManageDeliveries'],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/(?P<id>\d+)',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'show'],
                'permission_callback' => [$this, 'canManageDeliveries'],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/(?P<id>\d+)/status',
            [
                'methods' => 'PATCH',
                'callback' => [$this, 'updateStatus'],
                'permission_callback' => [$this, 'canManageDeliveries'],
            ]
        );
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response(
            $this->controller->index($request->get_query_params())
        );
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response(
            $this->controller->show(
                (int) ($request->get_url_params()['id'] ?? 0)
            )
        );
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response(
            $this->controller->store((array) $request->get_json_params()),
            201
        );
    }

    public function updateStatus(
        WP_REST_Request $request
    ): WP_REST_Response {
        return $this->response(
            $this->controller->updateStatus(
                (int) ($request->get_url_params()['id'] ?? 0),
                (array) $request->get_json_params()
            )
        );
    }

    public function canManageDeliveries(
        WP_REST_Request $request
    ): bool|WP_Error {
        return current_user_can('manage_options');
    }

    private function response(
        array $result,
        int $successStatus = 200
    ): WP_REST_Response {
        $status = ($result['success'] ?? false) === true
            ? $successStatus
            : match ($result['error']['code'] ?? '') {
                'validation_error' => 422,
                'invalid_delivery_state_transition' => 409,
                'delivery_not_found', 'order_not_found' => 404,
                default => 500,
            };

        return new WP_REST_Response($result, $status);
    }
}
