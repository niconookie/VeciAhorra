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
    private const PURCHASES = '/customer-panel/purchases';

    public function __construct(private CustomerPanelController $controller)
    {
    }

    public function register(): void
    {
        register_rest_route(
            self::NAMESPACE,
            self::PURCHASES,
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'purchases'],
                'permission_callback' => [$this, 'isAuthenticated'],
            ]
        );
        register_rest_route(
            self::NAMESPACE,
            self::PURCHASES . '/(?P<checkout_public_id>[^/]+)',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'purchase'],
                'permission_callback' => [$this, 'isAuthenticated'],
            ]
        );
    }

    public function purchases(WP_REST_Request $request): WP_REST_Response
    {
        return $this->privateResponse($this->controller->purchases(get_current_user_id()));
    }

    public function purchase(WP_REST_Request $request): WP_REST_Response
    {
        return $this->privateResponse($this->controller->purchase(
            get_current_user_id(),
            (string) ($request->get_url_params()['checkout_public_id'] ?? '')
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
                'invalid_query' => 422,
                'customer_order_not_found' => 404,
                default => 500,
            };

        return new WP_REST_Response($result, $status);
    }

    private function privateResponse(array $result): WP_REST_Response
    {
        $response = $this->response($result);
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('Vary', 'Cookie');

        return $response;
    }
}
