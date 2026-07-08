<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Routes;

use VeciAhorra\Modules\Payments\Controller\PaymentController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class PaymentRoutes
{
    private const NAMESPACE = 'veciahorra/v1';
    private const RESOURCE = '/payments';

    public function __construct(private PaymentController $controller)
    {
    }

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, self::RESOURCE, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'index'],
                'permission_callback' => [$this, 'canManagePayments'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'store'],
                'permission_callback' => [$this, 'canManagePayments'],
            ],
        ]);

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/(?P<id>\d+)',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'show'],
                'permission_callback' => [$this, 'canManagePayments'],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/(?P<id>\d+)/session',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createSession'],
                'permission_callback' => [$this, 'canManagePayments'],
            ]
        );
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response($this->controller->index());
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response($this->controller->show(
            (int) ($request->get_url_params()['id'] ?? 0)
        ));
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response(
            $this->controller->store((array) $request->get_json_params()),
            201
        );
    }

    public function createSession(
        WP_REST_Request $request
    ): WP_REST_Response {
        return $this->response($this->controller->createSession(
            (int) ($request->get_url_params()['id'] ?? 0)
        ));
    }

    public function canManagePayments(
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
                'payment_not_found' => 404,
                default => 500,
            };

        return new WP_REST_Response($result, $status);
    }
}
