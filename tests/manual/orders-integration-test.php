<?php

declare(strict_types=1);

use VeciAhorra\Core\Container;
use VeciAhorra\Modules\Orders\Routes\OrderRoutes;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertOrdersIntegration(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertOrdersIntegrationSame(mixed $expected, mixed $actual): void
{
    assertOrdersIntegration(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

function ordersIntegrationRequest(
    string $method,
    string $route,
    ?array $payload = null,
    array $query = []
): WP_REST_Response {
    $request = new WP_REST_Request($method, $route);

    if ($payload !== null) {
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode($payload));
    }

    if ($query !== []) {
        $request->set_query_params($query);
    }

    return rest_do_request($request);
}

function ordersMethodCount(
    array $routes,
    string $route,
    string $method
): int {
    $count = 0;

    foreach ($routes[$route] ?? [] as $handler) {
        if (($handler['methods'][$method] ?? false) === true) {
            $count++;
        }
    }

    return $count;
}

$resolved = (new Container())->make(OrderRoutes::class);
assertOrdersIntegration(
    $resolved instanceof OrderRoutes,
    'Container no resolvio la cadena de dependencias de OrderRoutes.'
);

$application = file_get_contents(
    dirname(__DIR__, 2) . '/app/Core/Application.php'
);
assertOrdersIntegration(is_string($application), 'No se pudo leer Application.');
assertOrdersIntegrationSame(
    1,
    substr_count($application, '$orderRoutes = $this->container->make')
);
assertOrdersIntegrationSame(
    1,
    substr_count($application, "[\$orderRoutes, 'register']")
);

$routes = rest_get_server()->get_routes();
$collection = '/veciahorra/v1/orders';
$detail = '/veciahorra/v1/orders/(?P<id>\d+)';
assertOrdersIntegrationSame(1, ordersMethodCount($routes, $collection, 'GET'));
assertOrdersIntegrationSame(1, ordersMethodCount($routes, $collection, 'POST'));
assertOrdersIntegrationSame(1, ordersMethodCount($routes, $detail, 'GET'));

$administratorIds = get_users([
    'role' => 'administrator',
    'number' => 1,
    'fields' => 'ids',
]);
assertOrdersIntegration($administratorIds !== [], 'Se requiere un administrador.');
wp_set_current_user((int) $administratorIds[0]);

global $wpdb;

$transaction = $wpdb->query('START TRANSACTION');
assertOrdersIntegration($transaction !== false, 'No se inicio la transaccion.');

try {
    $customerId = random_int(17000000, 17999999);
    $minimarketId = random_int(18000000, 18999999);
    $createdResponse = ordersIntegrationRequest('POST', $collection, [
        'customer_id' => $customerId,
        'minimarket_id' => $minimarketId,
        'items' => [
            [
                'product_id' => 701,
                'inventory_id' => 801,
                'quantity' => 2,
                'unit_price' => 900.25,
            ],
            [
                'product_id' => 702,
                'inventory_id' => 802,
                'quantity' => 1,
                'unit_price' => 100.0,
            ],
        ],
    ]);
    $created = $createdResponse->get_data();
    assertOrdersIntegrationSame(201, $createdResponse->get_status());
    assertOrdersIntegrationSame(true, $created['success'] ?? null);
    assertOrdersIntegrationSame('reserved', $created['data']['status'] ?? null);
    assertOrdersIntegrationSame('1900.50', $created['data']['total'] ?? null);
    assertOrdersIntegrationSame(2, count($created['data']['items'] ?? []));
    $orderId = (int) ($created['data']['id'] ?? 0);
    assertOrdersIntegration($orderId > 0, 'POST no retorno ID valido.');

    $listResponse = ordersIntegrationRequest('GET', $collection, null, [
        'customer_id' => (string) $customerId,
        'minimarket_id' => (string) $minimarketId,
        'status' => 'reserved',
    ]);
    assertOrdersIntegrationSame(200, $listResponse->get_status());
    assertOrdersIntegrationSame(true, $listResponse->get_data()['success'] ?? null);
    assertOrdersIntegrationSame(1, count($listResponse->get_data()['data'] ?? []));

    $showResponse = ordersIntegrationRequest(
        'GET',
        $collection . '/' . $orderId
    );
    assertOrdersIntegrationSame(200, $showResponse->get_status());
    assertOrdersIntegrationSame(
        $orderId,
        (int) ($showResponse->get_data()['data']['id'] ?? 0)
    );

    $missing = ordersIntegrationRequest(
        'GET',
        $collection . '/' . PHP_INT_MAX
    );
    assertOrdersIntegrationSame(404, $missing->get_status());
    assertOrdersIntegrationSame(
        'order_not_found',
        $missing->get_data()['error']['code'] ?? null
    );

    $invalid = ordersIntegrationRequest('POST', $collection, [
        'customer_id' => $customerId,
        'minimarket_id' => $minimarketId,
        'items' => [],
    ]);
    assertOrdersIntegrationSame(422, $invalid->get_status());
    assertOrdersIntegrationSame(
        'validation_error',
        $invalid->get_data()['error']['code'] ?? null
    );

    $originalPrefix = $wpdb->prefix;
    $wpdb->suppress_errors(true);

    try {
        $wpdb->prefix = 'missing_orders_integration_' . uniqid() . '_';
        $persistence = ordersIntegrationRequest('POST', $collection, [
            'customer_id' => 1,
            'minimarket_id' => 2,
            'items' => [[
                'product_id' => 1,
                'inventory_id' => 1,
                'quantity' => 1,
                'unit_price' => 1.0,
            ]],
        ]);
    } finally {
        $wpdb->prefix = $originalPrefix;
        $wpdb->suppress_errors(false);
    }

    assertOrdersIntegrationSame(500, $persistence->get_status());
    assertOrdersIntegrationSame(
        'persistence_error',
        $persistence->get_data()['error']['code'] ?? null
    );

    echo "PASS orders-integration-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
