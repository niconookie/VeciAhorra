<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Cart\Routes;

use InvalidArgumentException;
use VeciAhorra\Modules\Cart\Controller\CartController;
use VeciAhorra\Modules\Cart\Requests\CartItemCreateRequest;
use VeciAhorra\Modules\Cart\Requests\CartItemQuantityRequest;
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
                'permission_callback' => [$this, 'canAccessCart'],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'clear'],
                'permission_callback' => [$this, 'canAccessCart'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, self::RESOURCE . '/items', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'store'],
            'permission_callback' => [$this, 'canAccessCart'],
        ]);

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/items/(?P<id>\d+)',
            [
                [
                    'methods' => 'PATCH',
                    'callback' => [$this, 'updateQuantity'],
                    'permission_callback' => [$this, 'canAccessCart'],
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'delete'],
                    'permission_callback' => [$this, 'canAccessCart'],
                ],
            ]
        );
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $owner = $this->ownerOrError($request);

        return $owner instanceof WP_REST_Response
            ? $owner
            : $this->response($this->controller->index($owner));
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        $owner = $this->ownerOrError($request);

        if ($owner instanceof WP_REST_Response) {
            return $owner;
        }

        $body = $this->jsonObject($request);

        if ($body instanceof WP_REST_Response) {
            return $body;
        }

        try {
            $payload = (new CartItemCreateRequest($body))->validated();
        } catch (InvalidArgumentException $exception) {
            return $this->validationError($exception->getMessage());
        }

        $result = $this->controller->store([...$payload, ...$owner]);
        $status = ($result['success'] ?? false) === true
            && ($result['data']['created'] ?? false) === true
                ? 201
                : 200;

        return $this->response($result, $status);
    }

    public function updateQuantity(
        WP_REST_Request $request
    ): WP_REST_Response {
        $owner = $this->ownerOrError($request);

        if ($owner instanceof WP_REST_Response) {
            return $owner;
        }

        $body = $this->jsonObject($request);

        if ($body instanceof WP_REST_Response) {
            return $body;
        }

        try {
            $payload = (new CartItemQuantityRequest($body))->validated();
        } catch (InvalidArgumentException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->response($this->controller->updateQuantity(
            $owner,
            $this->id($request),
            $payload['quantity']
        ));
    }

    public function delete(WP_REST_Request $request): WP_REST_Response
    {
        $owner = $this->ownerOrError($request);

        return $owner instanceof WP_REST_Response
            ? $owner
            : $this->response($this->controller->delete(
                $owner,
                $this->id($request)
            ));
    }

    public function clear(WP_REST_Request $request): WP_REST_Response
    {
        $owner = $this->ownerOrError($request);

        return $owner instanceof WP_REST_Response
            ? $owner
            : $this->response($this->controller->clear($owner));
    }

    public function canAccessCart(WP_REST_Request $request): bool
    {
        return true;
    }

    /** @return array{session_id: ?string, user_id: ?int}|WP_REST_Response */
    private function ownerOrError(
        WP_REST_Request $request
    ): array|WP_REST_Response {
        $userId = get_current_user_id();

        if ($userId > 0) {
            return ['session_id' => null, 'user_id' => $userId];
        }

        $sessionId = $request->get_query_params()['session_id'] ?? null;

        if (! is_string($sessionId) || trim($sessionId) === '') {
            $sessionId = $request->get_header(
                'X-Veciahorra-Cart-Session'
            );
        }

        if (! is_string($sessionId) || trim($sessionId) === '') {
            return $this->badRequest(
                'cart_identity_required',
                'El carrito requiere una identidad.'
            );
        }

        return [
            'session_id' => sanitize_text_field(trim($sessionId)),
            'user_id' => null,
        ];
    }

    /** @return array<string, mixed>|WP_REST_Response */
    private function jsonObject(
        WP_REST_Request $request
    ): array|WP_REST_Response {
        if (! $request->is_json_content_type()) {
            return $this->badRequest(
                'invalid_json',
                'El cuerpo debe usar application/json.'
            );
        }

        $rawBody = trim($request->get_body());
        $object = json_decode($rawBody);

        if (
            $rawBody === ''
            || json_last_error() !== JSON_ERROR_NONE
            || ! is_object($object)
        ) {
            return $this->badRequest(
                'invalid_json',
                'El cuerpo debe ser un objeto JSON valido.'
            );
        }

        $decoded = json_decode($rawBody, true);

        return is_array($decoded)
            ? $decoded
            : $this->badRequest(
                'invalid_json',
                'No fue posible interpretar el cuerpo JSON.'
            );
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

    private function badRequest(
        string $code,
        string $message
    ): WP_REST_Response {
        return new WP_REST_Response([
            'success' => false,
            'error' => ['code' => $code, 'message' => $message],
        ], 400);
    }

    private function response(
        array $result,
        int $successStatus = 200
    ): WP_REST_Response {
        $status = ($result['success'] ?? false) === true
            ? $successStatus
            : match ($result['error']['code'] ?? '') {
                'validation_error' => 422,
                'cart_item_not_found' => 404,
                default => 500,
            };

        return new WP_REST_Response($result, $status);
    }
}
