<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Orders\Routes;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Modules\Orders\DTO\Admin\OrderAdminListQuery;
use VeciAhorra\Modules\Orders\Exceptions\OrderAdminReadException;
use VeciAhorra\Modules\Orders\Services\OrderAdminReadService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class OrdersAdminRoutes
{
    private const ALLOWED = [
        'search', 'store_id', 'order_status', 'fulfillment_mode',
        'date_from', 'date_to', 'sort', 'paged', 'per_page',
    ];

    public function __construct(private OrderAdminReadService $service)
    {
    }

    public function register(): void
    {
        register_rest_route('veciahorra/v1', '/orders/admin', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'index'],
            'permission_callback' => [$this, 'authorize'],
        ]);
    }

    public function authorize(WP_REST_Request $request): bool|WP_Error
    {
        if (! is_user_logged_in()) {
            return new WP_Error('rest_not_authenticated', 'Autenticacion requerida.', ['status' => 401]);
        }
        if (! current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', 'Permisos insuficientes.', ['status' => 403]);
        }
        $nonce = $request->get_header('X-WP-Nonce');
        if (! is_string($nonce) || $nonce === '') {
            return new WP_Error('rest_nonce_missing', 'Nonce requerido.', ['status' => 401]);
        }
        if (! wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('rest_cookie_invalid_nonce', 'Nonce invalido.', ['status' => 403]);
        }
        return true;
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $query = $this->query($request->get_query_params());
            $data = $this->service->listOrders($query)->toArray();
            $pagination = $data['pagination'];
            $pagination['previous_page'] = $pagination['page'] > 1
                ? $pagination['page'] - 1
                : null;
            $pagination['next_page'] = $pagination['page'] < $pagination['total_pages']
                ? $pagination['page'] + 1
                : null;
            return $this->response([
                'items' => $data['items'],
                'pagination' => $pagination,
                'filters' => [
                    'search' => $query->search,
                    'store_id' => $query->storeId,
                    'order_status' => $query->orderStatus,
                    'fulfillment_mode' => $query->fulfillmentMode,
                    'date_from' => $query->createdFrom,
                    'date_to' => $query->createdTo,
                ],
                'sort' => $query->order,
            ]);
        } catch (InvalidArgumentException) {
            return $this->response(['error' => ['code' => 'invalid_parameters']], 422);
        } catch (OrderAdminReadException $exception) {
            return $this->response(['error' => ['code' => $exception->errorCode]], 500);
        } catch (Throwable) {
            return $this->response(['error' => ['code' => 'orders_admin_read_failed']], 500);
        }
    }

    private function query(array $params): OrderAdminListQuery
    {
        foreach ($params as $name => $value) {
            if (! in_array((string) $name, self::ALLOWED, true) || is_array($value) || is_object($value)) {
                throw new InvalidArgumentException();
            }
        }
        $value = static function (string $name) use ($params): ?string {
            if (! array_key_exists($name, $params) || $params[$name] === '') return null;
            return is_string($params[$name]) ? $params[$name] : throw new InvalidArgumentException();
        };
        $positive = static function (?string $raw, ?int $default = null): ?int {
            if ($raw === null) return $default;
            if (preg_match('/^[1-9]\d*$/D', $raw) !== 1) throw new InvalidArgumentException();
            return (int) $raw;
        };
        $date = static function (?string $raw, bool $end): ?string {
            if ($raw === null) return null;
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            if ($parsed === false || $parsed->format('Y-m-d') !== $raw) throw new InvalidArgumentException();
            return $raw . ($end ? ' 23:59:59' : ' 00:00:00');
        };
        return new OrderAdminListQuery(
            search: $value('search'),
            storeId: $positive($value('store_id')),
            orderStatus: $value('order_status'),
            fulfillmentMode: $value('fulfillment_mode'),
            createdFrom: $date($value('date_from'), false),
            createdTo: $date($value('date_to'), true),
            page: $positive($value('paged'), 1) ?? 1,
            perPage: $positive($value('per_page'), 20) ?? 20,
            order: $value('sort') ?? 'newest'
        );
    }

    private function response(array $data, int $status = 200): WP_REST_Response
    {
        $response = new WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }
}
