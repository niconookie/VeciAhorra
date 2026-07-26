<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Contracts\OrderAdminReadRepositoryInterface;
use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalStateResolver;
use VeciAhorra\Modules\Orders\DTO\Admin\OrderAdminListQuery;
use VeciAhorra\Modules\Orders\Routes\OrdersAdminRoutes;
use VeciAhorra\Modules\Orders\Services\OrderAdminReadService;
use VeciAhorra\Modules\Orders\Services\OrderOperationalFactsAssembler;

require_once dirname(__DIR__, 5) . '/wp-load.php';

final class EmptyOrderAdminRepository implements OrderAdminReadRepositoryInterface
{
    public int $queries = 0;
    public function count(OrderAdminListQuery $query): int { $this->queries++; return 0; }
    public function paginate(OrderAdminListQuery $query): array { $this->queries++; return []; }
    public function loadFacts(array $orderIds): array { $this->queries++; return []; }
    public function findBase(int $orderId): ?array { $this->queries++; return null; }
}

$assertions = 0;
$assert = static function (bool $ok, string $message) use (&$assertions): void {
    if (!$ok) throw new RuntimeException($message); $assertions++;
};
$repository = new EmptyOrderAdminRepository();
$routes = new OrdersAdminRoutes(new OrderAdminReadService(
    $repository,
    new OrderOperationalFactsAssembler(),
    new OrderOperationalStateResolver(),
    '2026-07-26T12:00:00Z'
));
$request = new WP_REST_Request('GET', '/veciahorra/v1/orders/admin');
$request->set_query_params([]);
$response = $routes->index($request);
$body = $response->get_data();
$assert($response->get_status() === 200, 'Listado vacio no responde 200.');
$assert($repository->queries === 2, 'Listado vacio excede dos consultas.');
$assert(($response->get_headers()['Cache-Control'] ?? '') === 'private, no-store', 'Cache-Control invalido.');
$assert($body['items'] === [] && $body['pagination']['total'] === 0, 'Contrato vacio invalido.');
$assert(
    array_keys($body['pagination']) === [
        'page', 'per_page', 'total', 'total_pages',
        'previous_page', 'next_page',
    ],
    'Contrato de paginacion abierto o incompleto.'
);
$assert($body['pagination']['previous_page'] === null && $body['pagination']['next_page'] === null, 'Navegacion vacia invalida.');
foreach ([
    ['paged' => '0'], ['per_page' => '25'], ['sort' => 'id DESC'],
    ['store_id' => '1.5'], ['order_status' => 'cancelled'],
    ['fulfillment_mode' => 'courier'], ['date_from' => '2026-08-01', 'date_to' => '2026-07-01'],
    ['primary_state' => 'paid'], ['search' => ['bad']],
] as $query) {
    $invalid = new WP_REST_Request('GET', '/veciahorra/v1/orders/admin');
    $invalid->set_query_params($query);
    $result = $routes->index($invalid);
    $assert($result->get_status() === 422, 'Parametro invalido no fue rechazado.');
}
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
$assert($admin !== [], 'Se requiere administrador.');
wp_set_current_user(0);
$assert(is_wp_error($routes->authorize($request)), 'Anonimo autorizado.');
wp_set_current_user((int) $admin[0]);
$assert(is_wp_error($routes->authorize($request)), 'Nonce ausente autorizado.');
$request->set_header('X-WP-Nonce', 'invalid');
$assert(is_wp_error($routes->authorize($request)), 'Nonce invalido autorizado.');
$request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
$assert($routes->authorize($request) === true, 'Administrador con nonce rechazado.');

echo "PASS order-admin-list-rest-test assertions={$assertions} empty_queries={$repository->queries}\n";
