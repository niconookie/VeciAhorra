<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Catalog\Routes;

use VeciAhorra\Modules\Catalog\Controller\CatalogController;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class CatalogRoutes
{
    private const NAMESPACE = 'veciahorra/v1';
    private const RESOURCE = '/catalog/products';

    public function __construct(private CatalogController $controller)
    {
    }

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, self::RESOURCE, [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'index'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(
            self::NAMESPACE,
            self::RESOURCE . '/(?P<id>\d+)',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'show'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => [
                        'required' => true,
                        'validate_callback' => static fn (mixed $value): bool =>
                            is_numeric($value) && (int) $value > 0,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response($this->controller->index($request->get_query_params()));
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response($this->controller->show(
            (int) ($request->get_url_params()['id'] ?? 0)
        ));
    }

    private function response(array $result): WP_REST_Response
    {
        $status = ($result['success'] ?? false) === true
            ? 200
            : match ($result['error']['code'] ?? '') {
                'catalog_product_not_found' => 404,
                'validation_error' => 422,
                'catalog_unavailable' => 503,
                default => 500,
            };

        return new WP_REST_Response($result, $status);
    }
}
