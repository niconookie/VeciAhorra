<?php

declare(strict_types=1);

use VeciAhorra\Modules\Inventory\Repositories\InventoryRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertOrderRoute(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertOrderRouteSame(mixed $expected, mixed $actual): void
{
    assertOrderRoute(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

function orderRestRequest(
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

function orderRouteAccepts(
    array $routes,
    string $route,
    string $method
): bool {
    foreach ($routes[$route] ?? [] as $handler) {
        if (($handler['methods'][$method] ?? false) === true) {
            return true;
        }
    }

    return false;
}

global $wpdb;

$routes = rest_get_server()->get_routes();
$collection = '/veciahorra/v1/orders';
$itemPattern = '/veciahorra/v1/orders/(?P<id>\d+)';

assertOrderRoute(
    orderRouteAccepts($routes, $collection, 'GET'),
    'GET /orders no esta registrada.'
);
assertOrderRoute(
    orderRouteAccepts($routes, $collection, 'POST'),
    'POST /orders no esta registrada.'
);
assertOrderRoute(
    orderRouteAccepts($routes, $itemPattern, 'GET'),
    'GET /orders/{id} no usa el patron numerico.'
);
assertOrderRoute(
    ! array_key_exists('/veciahorra/v1/orders/(?P<id>[^/]+)', $routes),
    'La ruta detalle admite IDs no numericos.'
);

wp_set_current_user(0);
$anonymous = orderRestRequest('GET', $collection);
assertOrderRoute(
    in_array($anonymous->get_status(), [401, 403], true),
    'Orders no usa el permiso administrativo esperado.'
);

$administratorIds = get_users([
    'role' => 'administrator',
    'number' => 1,
    'fields' => 'ids',
]);
assertOrderRoute($administratorIds !== [], 'Se requiere un administrador.');
wp_set_current_user((int) $administratorIds[0]);

$transaction = $wpdb->query('START TRANSACTION');
assertOrderRoute($transaction !== false, 'No se inicio la transaccion.');

try {
    $customerId = random_int(15000000, 15999999);
    $minimarketId = random_int(16000000, 16999999);
    $now = current_time('mysql');
    $inventoryId = (new InventoryRepository())->create([
        'product_id' => 501, 'minimarket_id' => $minimarketId,
        'price' => 800.25, 'stock' => 10, 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $created = orderRestRequest('POST', $collection, [
        'customer_id' => $customerId,
        'minimarket_id' => $minimarketId,
        'items' => [[
            'product_id' => 501,
            'inventory_id' => $inventoryId,
            'quantity' => 2,
            'unit_price' => 800.25,
        ]],
    ]);
    $createdBody = $created->get_data();
    assertOrderRouteSame(201, $created->get_status());
    assertOrderRouteSame(true, $createdBody['success'] ?? null);
    $orderId = (int) ($createdBody['data']['id'] ?? 0);
    assertOrderRoute($orderId > 0, 'POST no delego la creacion.');

    $shown = orderRestRequest('GET', $collection . '/' . $orderId);
    assertOrderRouteSame(200, $shown->get_status());
    assertOrderRouteSame(
        $orderId,
        (int) ($shown->get_data()['data']['id'] ?? 0)
    );

    $listed = orderRestRequest('GET', $collection, null, [
        'customer_id' => (string) $customerId,
        'minimarket_id' => (string) $minimarketId,
        'status' => 'reserved',
    ]);
    assertOrderRouteSame(200, $listed->get_status());
    assertOrderRouteSame(1, count($listed->get_data()['data'] ?? []));
    assertOrderRouteSame(
        $orderId,
        (int) ($listed->get_data()['data'][0]['id'] ?? 0)
    );

    assertOrderRouteSame(
        404,
        orderRestRequest('GET', $collection . '/' . PHP_INT_MAX)->get_status()
    );
    assertOrderRouteSame(
        404,
        orderRestRequest('GET', $collection . '/no-numerico')->get_status()
    );

    $routesSource = file_get_contents(
        dirname(__DIR__, 2)
        . '/app/Modules/Orders/Routes/OrderRoutes.php'
    );
    assertOrderRoute(
        is_string($routesSource)
            && ! str_contains($routesSource, '$wpdb')
            && ! str_contains($routesSource, 'OrderRequest'),
        'OrderRoutes accede a datos o valida payloads.'
    );
    assertOrderRoute(
        preg_match('/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM)\b/i', $routesSource)
            !== 1,
        'OrderRoutes contiene SQL.'
    );
    assertOrderRoute(
        str_contains($routesSource, "current_user_can('manage_options')"),
        'OrderRoutes no comparte el permiso administrativo.'
    );

    $application = file_get_contents(
        dirname(__DIR__, 2) . '/app/Core/Application.php'
    );
    assertOrderRouteSame(
        1,
        substr_count($application, '$orderRoutes = $this->container->make')
    );
    assertOrderRouteSame(
        1,
        substr_count($application, "[\$orderRoutes, 'register']")
    );

    echo "PASS order-routes-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
