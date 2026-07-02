<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Routes;

use VeciAhorra\Modules\Products\Controllers\ProductController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Adaptador HTTP REST del módulo Products.
 */
final class ProductRoutes
{
    private const NAMESPACE = 'veciahorra/v1';

    private const RESOURCE = '/products';

    private const REST_STATUSES = [
        'active',
        'inactive',
    ];

    public function __construct(
        private ProductController $controller
    ) {
    }

    /**
     * Registra los endpoints REST de Products.
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
                    'permission_callback' => [
                        $this,
                        'canManageProducts',
                    ],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'store'],
                    'permission_callback' => [
                        $this,
                        'canManageProducts',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/(?P<id>[^/]+)',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'show'],
                    'permission_callback' => [
                        $this,
                        'canManageProducts',
                    ],
                    'args' => $this->idArgs(),
                ],
                [
                    'methods' => 'PATCH',
                    'callback' => [$this, 'update'],
                    'permission_callback' => [
                        $this,
                        'canManageProducts',
                    ],
                    'args' => $this->idArgs(),
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/(?P<id>[^/]+)/status',
            [
                'methods' => 'PATCH',
                'callback' => [$this, 'updateStatus'],
                'permission_callback' => [
                    $this,
                    'canManageProducts',
                ],
                'args' => $this->idArgs(),
            ]
        );
    }

    /**
     * Delega el listado de productos.
     */
    public function index(
        WP_REST_Request $request
    ): WP_REST_Response {
        $input = $request->get_query_params();

        return $this->toResponse(
            $this->controller->index($input)
        );
    }

    /**
     * Delega la creación de un producto.
     */
    public function store(
        WP_REST_Request $request
    ): WP_REST_Response {
        $body = $this->jsonObject($request);

        if ($body instanceof WP_REST_Response) {
            return $body;
        }

        return $this->toResponse(
            $this->controller->store($body),
            201
        );
    }

    /**
     * Delega la consulta de un producto.
     */
    public function show(
        WP_REST_Request $request
    ): WP_REST_Response {
        return $this->toResponse(
            $this->controller->show(
                (int) $request->get_url_params()['id']
            )
        );
    }

    /**
     * Delega la actualización parcial de un producto.
     */
    public function update(
        WP_REST_Request $request
    ): WP_REST_Response {
        $body = $this->jsonObject($request);

        if ($body instanceof WP_REST_Response) {
            return $body;
        }

        return $this->toResponse(
            $this->controller->update(
                (int) $request->get_url_params()['id'],
                $body
            )
        );
    }

    /**
     * Delega el cambio de estado de un producto.
     */
    public function updateStatus(
        WP_REST_Request $request
    ): WP_REST_Response {
        $body = $this->jsonObject($request);

        if ($body instanceof WP_REST_Response) {
            return $body;
        }

        if (
            ! array_key_exists('status', $body)
            || ! is_string($body['status'])
            || ! in_array($body['status'], self::REST_STATUSES, true)
        ) {
            return $this->badRequest(
                'invalid_status',
                'El estado REST debe ser active o inactive.'
            );
        }

        return $this->toResponse(
            $this->controller->updateStatus(
                (int) $request->get_url_params()['id'],
                $body
            )
        );
    }

    /**
     * Autoriza el acceso administrativo a Products.
     */
    public function canManageProducts(
        WP_REST_Request $request
    ): bool|WP_Error {
        return current_user_can('manage_options');
    }

    /**
     * Define la validación del parámetro de ruta ID.
     *
     * @return array<string, array<string, mixed>>
     */
    private function idArgs(): array
    {
        return [
            'id' => [
                'required' => true,
                'validate_callback' => static function (
                    mixed $value,
                    WP_REST_Request $request
                ): bool {
                    $urlParams = $request->get_url_params();

                    return self::isPositiveInteger(
                        $urlParams['id'] ?? null
                    );
                },
                'sanitize_callback' => static function (
                    mixed $value,
                    WP_REST_Request $request
                ): int {
                    $urlParams = $request->get_url_params();

                    return (int) ($urlParams['id'] ?? 0);
                },
            ],
        ];
    }

    /**
     * Indica si un valor representa un entero positivo.
     */
    private static function isPositiveInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);

        if ($value === '' || ! ctype_digit($value)) {
            return false;
        }

        $value = ltrim($value, '0');

        if ($value === '') {
            return false;
        }

        $maximum = (string) PHP_INT_MAX;
        $length = strlen($value);
        $maximumLength = strlen($maximum);

        if ($length !== $maximumLength) {
            return $length < $maximumLength;
        }

        return strcmp($value, $maximum) <= 0;
    }

    /**
     * Obtiene un objeto JSON como array neutral.
     *
     * @return array<string, mixed>|WP_REST_Response
     */
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

        if ($rawBody === '') {
            return $this->badRequest(
                'invalid_json',
                'El cuerpo debe ser un objeto JSON.'
            );
        }

        $decodedObject = json_decode($rawBody);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->badRequest(
                'invalid_json',
                'El cuerpo contiene JSON inválido.'
            );
        }

        if (! is_object($decodedObject)) {
            return $this->badRequest(
                'invalid_json',
                'El cuerpo debe ser un objeto JSON.'
            );
        }

        $body = json_decode($rawBody, true);

        if (! is_array($body)) {
            return $this->badRequest(
                'invalid_json',
                'No fue posible interpretar el cuerpo JSON.'
            );
        }

        return $body;
    }

    /**
     * Construye una respuesta para un error de transporte.
     */
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

    /**
     * Convierte el resultado neutral del Controller a REST.
     */
    private function toResponse(
        array $result,
        int $successStatus = 200
    ): WP_REST_Response {
        $status = ($result['success'] ?? false) === true
            ? $successStatus
            : $this->errorStatus(
                (string) ($result['error']['code'] ?? '')
            );

        return new WP_REST_Response(
            $result,
            $status
        );
    }

    /**
     * Obtiene el código HTTP de un error del Controller.
     */
    private function errorStatus(string $code): int
    {
        return match ($code) {
            'validation_error' => 422,
            'product_not_found' => 404,
            'persistence_error',
            'internal_error' => 500,
            default => 500,
        };
    }
}
