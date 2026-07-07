<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Reservations\Routes;

use VeciAhorra\Modules\Reservations\Controller\ReservationController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ReservationRoutes
{
    private const NAMESPACE = 'veciahorra/v1';
    private const RESOURCE = '/reservations';

    public function __construct(private ReservationController $controller)
    {
    }

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, self::RESOURCE, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'index'],
                'permission_callback' => [$this, 'canManageReservations'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'store'],
                'permission_callback' => [$this, 'canManageReservations'],
            ],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response($this->controller->index($request->get_query_params()));
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response($this->controller->store((array) $request->get_json_params()), 201);
    }

    public function canManageReservations(WP_REST_Request $request): bool|WP_Error
    {
        return current_user_can('manage_options');
    }

    private function response(array $result, int $successStatus = 200): WP_REST_Response
    {
        $status = ($result['success'] ?? false) === true
            ? $successStatus
            : (($result['error']['code'] ?? '') === 'validation_error' ? 422 : 500);

        return new WP_REST_Response($result, $status);
    }
}
