<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Routes;

use VeciAhorra\Modules\Stores\Controllers\StoreAdminReadController;
use VeciAhorra\Modules\Stores\Exceptions\StoreListValidationException;
use VeciAhorra\Modules\Stores\Requests\StoreListRequest;
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

    private function response(array $data, int $status): WP_REST_Response
    {
        $response = new WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'private, no-store');

        return $response;
    }
}
