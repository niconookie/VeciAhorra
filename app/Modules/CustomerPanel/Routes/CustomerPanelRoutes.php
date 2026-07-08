<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\CustomerPanel\Routes;

use VeciAhorra\Modules\CustomerPanel\Controller\CustomerPanelController;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class CustomerPanelRoutes
{
    private const NAMESPACE = 'veciahorra/v1';
    private const RESOURCE = '/me/orders';

    public function __construct(private CustomerPanelController $controller)
    {
    }

    public function register(): void
    {
        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE,
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'index'],
                'permission_callback' => [$this, 'isAuthenticated'],
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/(?P<id>\d+)',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'show'],
                'permission_callback' => [$this, 'isAuthenticated'],
            ]
        );
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response($this->controller->index(
            get_current_user_id()
        ));
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response($this->controller->show(
            get_current_user_id(),
            (int) ($request->get_url_params()['id'] ?? 0)
        ));
    }

    public function isAuthenticated(WP_REST_Request $request): bool
    {
        return is_user_logged_in();
    }

    private function response(array $result): WP_REST_Response
    {
        $status = ($result['success'] ?? false) === true
            ? 200
            : match ($result['error']['code'] ?? '') {
                'validation_error' => 422,
                'order_not_found' => 404,
                default => 500,
            };

        return new WP_REST_Response($result, $status);
    }
}
