<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertInventoryRouteTrue(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertInventoryRouteSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function inventoryRestRequest(
    string $method,
    string $route,
    ?array $body = null,
    array $query = []
): WP_REST_Response {
    $request = new WP_REST_Request($method, $route);

    if ($body !== null) {
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode($body));
    }

    if ($query !== []) {
        $request->set_query_params($query);
    }

    return rest_do_request($request);
}

function routeAccepts(array $routes, string $route, string $method): bool
{
    foreach ($routes[$route] ?? [] as $handler) {
        if (($handler['methods'][$method] ?? false) === true) {
            return true;
        }
    }

    return false;
}

global $wpdb;

$administratorIds = get_users([
    'role' => 'administrator',
    'number' => 1,
    'fields' => 'ids',
]);

assertInventoryRouteTrue(
    $administratorIds !== [],
    'La prueba requiere al menos un usuario administrador.'
);

wp_set_current_user((int) $administratorIds[0]);

$transactionStarted = $wpdb->query('START TRANSACTION');
assertInventoryRouteTrue(
    $transactionStarted !== false,
    'No fue posible iniciar la transaccion.'
);

try {
    $routes = rest_get_server()->get_routes();
    $collection = '/veciahorra/v1/inventory';
    $item = '/veciahorra/v1/inventory/(?P<id>\d+)';

    foreach (
        [
            [$collection, 'GET'],
            [$collection, 'POST'],
            [$item, 'GET'],
            [$item, 'PUT'],
            [$item, 'PATCH'],
            [$item, 'DELETE'],
            [$item . '/price', 'PATCH'],
            [$item . '/stock', 'PATCH'],
            [$item . '/status', 'PATCH'],
        ] as [$route, $method]
    ) {
        assertInventoryRouteTrue(
            routeAccepts($routes, $route, $method),
            sprintf('La ruta %s no acepta %s.', $route, $method)
        );
    }

    $productId = random_int(5000000, 5999999);
    $minimarketId = random_int(6000000, 6999999);
    $created = inventoryRestRequest('POST', $collection, [
        'product_id' => $productId,
        'minimarket_id' => $minimarketId,
        'price' => '1200.50',
        'stock' => '8',
        'status' => 'active',
    ]);
    $createdBody = $created->get_data();

    assertInventoryRouteSame(201, $created->get_status());
    assertInventoryRouteSame(true, $createdBody['success'] ?? null);
    $id = (int) ($createdBody['data']['id'] ?? 0);
    assertInventoryRouteTrue($id > 0, 'POST no retorno un ID valido.');

    $list = inventoryRestRequest('GET', $collection, null, [
        'product_id' => (string) $productId,
        'page' => '1',
        'per_page' => '10',
    ]);
    $listBody = $list->get_data();
    assertInventoryRouteSame(200, $list->get_status());
    assertInventoryRouteSame(1, $listBody['meta']['total'] ?? null);

    $show = inventoryRestRequest('GET', $collection . '/' . $id);
    assertInventoryRouteSame(200, $show->get_status());
    assertInventoryRouteSame(
        $id,
        (int) ($show->get_data()['data']['id'] ?? 0)
    );

    $put = inventoryRestRequest('PUT', $collection . '/' . $id, [
        'price' => 1300,
        'stock' => 7,
    ]);
    assertInventoryRouteSame(200, $put->get_status());

    $patch = inventoryRestRequest('PATCH', $collection . '/' . $id, [
        'status' => 'inactive',
    ]);
    assertInventoryRouteSame(200, $patch->get_status());

    $price = inventoryRestRequest(
        'PATCH',
        $collection . '/' . $id . '/price',
        ['price' => '1400.25']
    );
    assertInventoryRouteSame(200, $price->get_status());
    assertInventoryRouteSame(
        1400.25,
        $price->get_data()['data']['price'] ?? null
    );

    $stock = inventoryRestRequest(
        'PATCH',
        $collection . '/' . $id . '/stock',
        ['stock' => '5']
    );
    assertInventoryRouteSame(200, $stock->get_status());
    assertInventoryRouteSame(5, $stock->get_data()['data']['stock'] ?? null);

    $status = inventoryRestRequest(
        'PATCH',
        $collection . '/' . $id . '/status',
        ['status' => 'active']
    );
    assertInventoryRouteSame(200, $status->get_status());
    assertInventoryRouteSame(
        'active',
        $status->get_data()['data']['status'] ?? null
    );

    $invalid = inventoryRestRequest(
        'PATCH',
        $collection . '/' . $id . '/status',
        ['status' => 'draft']
    );
    assertInventoryRouteSame(422, $invalid->get_status());
    assertInventoryRouteSame(
        'validation_error',
        $invalid->get_data()['error']['code'] ?? null
    );

    $delete = inventoryRestRequest('DELETE', $collection . '/' . $id);
    assertInventoryRouteSame(200, $delete->get_status());
    assertInventoryRouteSame(
        true,
        $delete->get_data()['data']['deleted'] ?? null
    );
    assertInventoryRouteSame(
        404,
        inventoryRestRequest('GET', $collection . '/' . $id)->get_status()
    );

    $routesFile = file_get_contents(
        dirname(__DIR__, 2)
        . '/app/Modules/Inventory/Routes/InventoryRoutes.php'
    );
    assertInventoryRouteTrue(
        ! str_contains($routesFile, '$wpdb'),
        'InventoryRoutes contiene acceso directo a $wpdb.'
    );
    assertInventoryRouteTrue(
        preg_match('/\b(SELECT|INSERT INTO|UPDATE .* SET|DELETE FROM)\b/i', $routesFile)
        !== 1,
        'InventoryRoutes contiene SQL.'
    );

    $applicationFile = file_get_contents(
        dirname(__DIR__, 2) . '/app/Core/Application.php'
    );
    assertInventoryRouteSame(
        1,
        substr_count($applicationFile, '$inventoryRoutes = $this->container->make')
    );
    assertInventoryRouteSame(
        1,
        substr_count($applicationFile, "[\$inventoryRoutes, 'register']")
    );

    echo "PASS inventory-routes-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
