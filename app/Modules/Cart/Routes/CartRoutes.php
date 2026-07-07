<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Cart\Routes;

use InvalidArgumentException;
use VeciAhorra\Modules\Cart\Controller\CartController;
use VeciAhorra\Modules\Cart\Requests\CartItemCreateRequest;
use VeciAhorra\Modules\Cart\Requests\CartItemQuantityRequest;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class CartRoutes
{
    private const NAMESPACE = 'veciahorra/v1';
    private const RESOURCE = '/cart';

    public function __construct(private CartController $controller)
    {
    }

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, self::RESOURCE, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'index'],
                'permission_callback' => [$this, 'canManageCart'],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'clear'],
                'permission_callback' => [$this, 'canManageCart'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, self::RESOURCE . '/items', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'store'],
            'permission_callback' => [$this, 'canManageCart'],
        ]);

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/items/(?P<id>\d+)',
            [
                [
                    'methods' => 'PATCH',
                    'callback' => [$this, 'updateQuantity'],
                    'permission_callback' => [$this, 'canManageCart'],
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'delete'],
                    'permission_callback' => [$this, 'canManageCart'],
                ],
            ]
        );
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response(
            $this->controller->index($this->owner($request))
        );
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $payload = (new CartItemCreateRequest(
                (array) $request->get_json_params()
            ))->validated();
            $payload = [...$payload, ...$this->owner($request)];
        } catch (InvalidArgumentException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->response($this->controller->store($payload), 201);
    }

    public function updateQuantity(
        WP_REST_Request $request
    ): WP_REST_Response {
        try {
            $payload = (new CartItemQuantityRequest(
                (array) $request->get_json_params()
            ))->validated();
        } catch (InvalidArgumentException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->response($this->controller->updateQuantity(
            $this->owner($request),
            $this->id($request),
            $payload['quantity']
        ));
    }

    public function delete(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response(
            $this->controller->delete(
                $this->owner($request),
                $this->id($request)
            )
        );
    }

    public function clear(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response(
            $this->controller->clear($this->owner($request))
        );
    }

    public function canManageCart(WP_REST_Request $request): bool|WP_Error
    {
        return current_user_can('manage_options');
    }

    private function owner(WP_REST_Request $request): array
    {
        $params = $request->get_query_params();
        $userId = filter_var(
            $params['user_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return [
            'session_id' => isset($params['session_id'])
                ? sanitize_text_field((string) $params['session_id'])
                : null,
            'user_id' => $userId === false ? null : $userId,
        ];
    }

    private function id(WP_REST_Request $request): int
    {
        return (int) ($request->get_url_params()['id'] ?? 0);
    }

    private function validationError(string $message): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => false,
            'error' => [
                'code' => 'validation_error',
                'message' => $message,
            ],
        ], 422);
    }

    private function response(
        array $result,
        int $successStatus = 200
    ): WP_REST_Response {
        $status = ($result['success'] ?? false) === true
            ? $successStatus
            : (($result['error']['code'] ?? '') === 'validation_error'
                ? 422
                : 500);

        return new WP_REST_Response($result, $status);
    }
}
