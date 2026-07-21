<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Routes;

use VeciAhorra\Modules\Stores\Controllers\StoreAdminReadController;
use VeciAhorra\Modules\Stores\Exceptions\StoreListValidationException;
use VeciAhorra\Modules\Stores\Requests\StoreListRequest;
use VeciAhorra\Modules\Stores\Requests\StoreTransitionRequest;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Transporte REST de solo lectura para Store administrativo.
 */
final class StoreRoutes
{
    private const NAMESPACE = 'veciahorra/v1';

    private const RESOURCE = '/stores';

    public function __construct(private StoreAdminReadController $controller)
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
                'permission_callback' => [$this, 'canManageStores'],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/(?P<id>[^/]+)',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'show'],
                    'permission_callback' => [$this, 'canManageStoreResource'],
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'delete'],
                    'permission_callback' => [$this, 'canManageStoreResource'],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/(?P<id>[^/]+)/transitions',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'transition'],
                'permission_callback' => [$this, 'canManageStoreResource'],
            ]
        );
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $query = (new StoreListRequest(
                $request->get_query_params()
            ))->validated();
        } catch (StoreListValidationException $exception) {
            return $this->response([
                'success' => false,
                'error' => [
                    'code' => 'validation_error',
                    'message' => $exception->getMessage(),
                    'details' => [
                        'field' => $exception->field(),
                    ],
                ],
            ], 422);
        }

        $result = $this->controller->index($query);
        $status = ($result['success'] ?? false) === true ? 200 : 503;

        return $this->response($result, $status);
    }

    public function canManageStores(
        WP_REST_Request $request
    ): bool|WP_Error {
        return current_user_can('manage_options');
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $id = $this->validatedId($request);
        return $id instanceof WP_REST_Response
            ? $id
            : $this->toResponse($this->controller->show($id));
    }

    public function transition(WP_REST_Request $request): WP_REST_Response
    {
        $id = $this->validatedId($request);
        if ($id instanceof WP_REST_Response) {
            return $id;
        }
        $body = $this->jsonObject($request);
        if ($body instanceof WP_REST_Response) {
            return $body;
        }
        try {
            $action = (new StoreTransitionRequest($body))->validated();
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse('validation_error', $exception->getMessage(), 422, 'invalid_payload');
        }

        return $this->toResponse($this->controller->transition($id, $action));
    }

    public function delete(WP_REST_Request $request): WP_REST_Response
    {
        $id = $this->validatedId($request);
        if ($id instanceof WP_REST_Response) {
            return $id;
        }
        if (trim((string) $request->get_body()) !== '') {
            return $this->errorResponse(
                'invalid_request',
                'DELETE no acepta cuerpo.',
                400,
                'body_not_allowed'
            );
        }
        $result = $this->controller->delete($id);
        return ($result['success'] ?? false) === true
            ? $this->noContentResponse()
            : $this->toResponse($result);
    }

    public function canManageStoreResource(WP_REST_Request $request): bool|WP_Error
    {
        if (! current_user_can('manage_options')) {
            return false;
        }
        $nonce = $request->get_header('X-WP-Nonce');
        if (! is_string($nonce) || $nonce === '' || ! wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error(
                'rest_cookie_invalid_nonce',
                'El nonce REST no es valido.',
                ['status' => 403]
            );
        }

        return true;
    }

    private function validatedId(WP_REST_Request $request): int|WP_REST_Response
    {
        $value = $request->get_url_params()['id'] ?? null;
        if (! is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            return $this->errorResponse('validation_error', 'El ID de Store no es valido.', 422, 'invalid_id', 'id');
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($id) && $id > 0
            ? $id
            : $this->errorResponse('validation_error', 'El ID de Store no es valido.', 422, 'invalid_id', 'id');
    }

    private function jsonObject(WP_REST_Request $request): array|WP_REST_Response
    {
        if (! $request->is_json_content_type()) {
            return $this->errorResponse('invalid_json', 'El cuerpo debe usar application/json.', 400, 'invalid_content_type');
        }
        $raw = trim((string) $request->get_body());
        $object = json_decode($raw);
        if ($raw === '' || json_last_error() !== JSON_ERROR_NONE || ! is_object($object)) {
            return $this->errorResponse('invalid_json', 'El cuerpo debe ser un objeto JSON valido.', 400, 'invalid_json');
        }
        $body = json_decode($raw, true);
        return is_array($body)
            ? $body
            : $this->errorResponse('invalid_json', 'No fue posible interpretar el cuerpo JSON.', 400, 'invalid_json');
    }

    private function toResponse(array $result): WP_REST_Response
    {
        if (($result['success'] ?? false) === true) {
            return $this->response($result, 200);
        }
        $code = (string) ($result['error']['code'] ?? 'internal_error');
        $status = match ($code) {
            'store_not_found' => 404,
            'concurrent_modification', 'store_referenced', 'action_not_allowed' => 409,
            'invalid_combination' => 422,
            'persistence_failure', 'internal_error' => 500,
            default => 500,
        };
        $result['error']['data']['status'] = $status;

        return $this->response($result, $status);
    }

    private function errorResponse(
        string $code,
        string $message,
        int $status,
        string $reason,
        ?string $field = null
    ): WP_REST_Response {
        $data = ['status' => $status, 'reason' => $reason];
        if ($field !== null) {
            $data['field'] = $field;
        }
        return $this->response([
            'success' => false,
            'error' => ['code' => $code, 'message' => $message, 'data' => $data],
        ], $status);
    }

    private function response(array $data, int $status): WP_REST_Response
    {
        $response = new WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'private, no-store');

        return $response;
    }

    private function noContentResponse(): WP_REST_Response
    {
        $response = new WP_REST_Response(null, 204);
        $response->header('Cache-Control', 'private, no-store');

        return $response;
    }
}
