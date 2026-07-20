<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Routes;

use InvalidArgumentException;
use VeciAhorra\Modules\Inventory\Exceptions\InventoryValidationException;
use VeciAhorra\Modules\Inventory\Controllers\InventoryController;
use VeciAhorra\Modules\Inventory\Requests\InventoryCreateRequest;
use VeciAhorra\Modules\Inventory\Requests\InventoryListRequest;
use VeciAhorra\Modules\Inventory\Requests\InventoryUpdateRequest;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Adaptador HTTP REST del modulo Inventory.
 */
final class InventoryRoutes
{
    private const NAMESPACE = 'veciahorra/v1';

    private const RESOURCE = '/inventory';

    public function __construct(
        private InventoryController $controller
    ) {
    }

    /**
     * Registra los endpoints REST de Inventory.
     */
    public function register(): void
    {
        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE,
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'index'],
                    'permission_callback' => [$this, 'canManageInventory'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'create'],
                    'permission_callback' => [$this, 'canManageInventory'],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/(?P<id>\d+)',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'show'],
                    'permission_callback' => [$this, 'canManageInventory'],
                    'args' => $this->idArgs(),
                ],
                [
                    'methods' => ['PUT', 'PATCH'],
                    'callback' => [$this, 'update'],
                    'permission_callback' => [$this, 'canManageInventory'],
                    'args' => $this->idArgs(),
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'delete'],
                    'permission_callback' => [$this, 'canManageInventory'],
                    'args' => $this->idArgs(),
                ],
            ]
        );

        foreach (
            [
                'price' => 'updatePrice',
                'stock' => 'updateStock',
                'status' => 'changeStatus',
            ] as $field => $callback
        ) {
            register_rest_route(
                self::NAMESPACE,
                self::RESOURCE . '/(?P<id>\d+)/' . $field,
                [
                    'methods' => 'PATCH',
                    'callback' => [$this, $callback],
                    'permission_callback' => [$this, 'canManageInventory'],
                    'args' => $this->idArgs(),
                ]
            );
        }
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $query = (new InventoryListRequest(
                $request->get_query_params()
            ))->validated();
        } catch (InvalidArgumentException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->toResponse($this->controller->index($query));
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return $this->toResponse(
            $this->controller->show($this->id($request))
        );
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $this->validatedBody($request, InventoryCreateRequest::class);

        if ($payload instanceof WP_REST_Response) {
            return $payload;
        }

        return $this->toResponse($this->controller->create($payload), 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $this->validatedBody($request, InventoryUpdateRequest::class);

        if ($payload instanceof WP_REST_Response) {
            return $payload;
        }

        return $this->toResponse(
            $this->controller->update($this->id($request), $payload)
        );
    }

    public function delete(WP_REST_Request $request): WP_REST_Response
    {
        return $this->toResponse(
            $this->controller->delete($this->id($request))
        );
    }

    public function updatePrice(WP_REST_Request $request): WP_REST_Response
    {
        return $this->updateField($request, 'price', 'updatePrice');
    }

    public function updateStock(WP_REST_Request $request): WP_REST_Response
    {
        return $this->updateField($request, 'stock', 'updateStock');
    }

    public function changeStatus(WP_REST_Request $request): WP_REST_Response
    {
        return $this->updateField($request, 'status', 'changeStatus');
    }

    public function canManageInventory(
        WP_REST_Request $request
    ): bool|WP_Error {
        return current_user_can('manage_options');
    }

    private function updateField(
        WP_REST_Request $request,
        string $field,
        string $controllerMethod
    ): WP_REST_Response {
        $payload = $this->validatedBody($request, InventoryUpdateRequest::class);

        if ($payload instanceof WP_REST_Response) {
            return $payload;
        }

        if (! array_key_exists($field, $payload)) {
            return $this->validationError(sprintf(
                'El campo %s es obligatorio.',
                $field
            ));
        }

        return $this->toResponse(
            $this->controller->{$controllerMethod}(
                $this->id($request),
                [$field => $payload[$field]]
            )
        );
    }

    /**
     * @param class-string<InventoryCreateRequest|InventoryUpdateRequest> $requestClass
     * @return array<string, mixed>|WP_REST_Response
     */
    private function validatedBody(
        WP_REST_Request $request,
        string $requestClass
    ): array|WP_REST_Response {
        $body = $this->jsonObject($request);

        if ($body instanceof WP_REST_Response) {
            return $body;
        }

        try {
            return (new $requestClass($body))->validated();
        } catch (InventoryValidationException $exception) {
            return $this->validationError(
                $exception->getMessage(),
                $exception->field(),
                $exception->reason()
            );
        } catch (InvalidArgumentException $exception) {
            return $this->validationError($exception->getMessage());
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function idArgs(): array
    {
        return [
            'id' => [
                'required' => true,
                'validate_callback' => static fn (mixed $value): bool =>
                    is_numeric($value) && (int) $value > 0,
                'sanitize_callback' => static fn (mixed $value): int =>
                    (int) $value,
            ],
        ];
    }

    private function id(WP_REST_Request $request): int
    {
        return (int) ($request->get_url_params()['id'] ?? 0);
    }

    /** @return array<string, mixed>|WP_REST_Response */
    private function jsonObject(
        WP_REST_Request $request
    ): array|WP_REST_Response {
        if (! $request->is_json_content_type()) {
            return $this->badRequest(
                'invalid_json',
                'El cuerpo debe usar el tipo de contenido application/json.'
            );
        }

        $rawBody = trim($request->get_body());
        $decoded = json_decode($rawBody);

        if (
            $rawBody === ''
            || json_last_error() !== JSON_ERROR_NONE
            || ! is_object($decoded)
        ) {
            return $this->badRequest(
                'invalid_json',
                'El cuerpo debe ser un objeto JSON valido.'
            );
        }

        $body = json_decode($rawBody, true);

        return is_array($body)
            ? $body
            : $this->badRequest(
                'invalid_json',
                'No fue posible interpretar el cuerpo JSON.'
            );
    }

    private function validationError(
        string $message,
        ?string $field = null,
        ?string $reason = null
    ): WP_REST_Response {
        $error = [
            'code' => 'validation_error',
            'message' => $message,
        ];

        if ($field !== null && $reason !== null) {
            $error['details'] = [
                'field' => $field,
                'reason' => $reason,
            ];
        }

        return new WP_REST_Response(
            [
                'success' => false,
                'error' => $error,
            ],
            422
        );
    }

    private function badRequest(
        string $code,
        string $message
    ): WP_REST_Response {
        return new WP_REST_Response(
            [
                'success' => false,
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ],
            400
        );
    }

    private function toResponse(
        array $result,
        int $successStatus = 200
    ): WP_REST_Response {
        $status = ($result['success'] ?? false) === true
            ? $successStatus
            : match ($result['error']['code'] ?? '') {
                'validation_error' => 422,
                'inventory_not_found' => 404,
                default => 500,
            };

        return new WP_REST_Response($result, $status);
    }
}
