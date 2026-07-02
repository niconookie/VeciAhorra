<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;

require_once dirname(__DIR__, 5) . '/wp-load.php';

$tests = [];

function test(string $name, callable $callback): void
{
    global $tests;
    $tests[] = [$name, $callback];
}

function assertTrue(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertSameValue(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function restRequest(
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

global $wpdb;

$administratorIds = get_users([
    'role' => 'administrator',
    'number' => 1,
    'fields' => 'ids',
]);

assertTrue(
    $administratorIds !== [],
    'La prueba requiere al menos un usuario administrador.'
);

$administratorId = (int) $administratorIds[0];
wp_set_current_user($administratorId);

$table = $wpdb->prefix . Config::TABLE_PREFIX . 'products';
$transactionStarted = $wpdb->query('START TRANSACTION');

assertTrue(
    $transactionStarted !== false,
    'No fue posible iniciar la transaccion de prueba.'
);

try {
    $suffix = str_replace('.', '', uniqid('bulkroute', true));
    $now = '2020-01-01 00:00:00';
    $ids = [];

    for ($index = 1; $index <= 2; $index++) {
        $inserted = $wpdb->insert(
            $table,
            [
                'name' => sprintf('Producto bulk route %d', $index),
                'slug' => sprintf('%s-%d', $suffix, $index),
                'sku' => sprintf('%s-%d', strtoupper($suffix), $index),
                'status' => 'draft',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        assertSameValue(1, $inserted);
        $ids[] = (int) $wpdb->insert_id;
    }

    test('01. Existen las cuatro rutas bulk PATCH', function (): void {
        $routes = rest_get_server()->get_routes();

        foreach (['status', 'category', 'brand', 'unit'] as $operation) {
            $route = '/veciahorra/v1/products/bulk/' . $operation;
            assertTrue(
                array_key_exists($route, $routes),
                sprintf('No existe la ruta %s.', $route)
            );

            $acceptsPatch = false;

            foreach ($routes[$route] as $handler) {
                if (($handler['methods']['PATCH'] ?? false) === true) {
                    $acceptsPatch = true;
                    break;
                }
            }

            assertTrue(
                $acceptsPatch,
                sprintf('La ruta %s no acepta PATCH.', $route)
            );
        }
    });

    test('02. bulk/status delega correctamente', function () use ($ids): void {
        $response = restRequest(
            'PATCH',
            '/veciahorra/v1/products/bulk/status',
            ['ids' => $ids, 'status' => 'active']
        );
        $body = $response->get_data();

        assertSameValue(200, $response->get_status());
        assertSameValue(true, $body['success'] ?? null);
        assertSameValue(2, $body['data']['requested'] ?? null);
        assertSameValue(2, $body['data']['affected'] ?? null);
    });

    test('03. bulk/category delega correctamente', function () use (
        $ids,
        $table,
        $wpdb
    ): void {
        $response = restRequest(
            'PATCH',
            '/veciahorra/v1/products/bulk/category',
            ['ids' => $ids, 'category_id' => 10]
        );

        assertSameValue(200, $response->get_status());
        assertSameValue(
            2,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE id IN (%d, %d) AND category_id = %d",
                    ...[...$ids, 10]
                )
            )
        );
    });

    test('04. bulk/brand delega correctamente', function () use (
        $ids,
        $table,
        $wpdb
    ): void {
        $response = restRequest(
            'PATCH',
            '/veciahorra/v1/products/bulk/brand',
            ['ids' => $ids, 'brand_id' => 20]
        );

        assertSameValue(200, $response->get_status());
        assertSameValue(
            2,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE id IN (%d, %d) AND brand_id = %d",
                    ...[...$ids, 20]
                )
            )
        );
    });

    test('05. bulk/unit delega correctamente', function () use (
        $ids,
        $table,
        $wpdb
    ): void {
        $response = restRequest(
            'PATCH',
            '/veciahorra/v1/products/bulk/unit',
            ['ids' => $ids, 'unit_id' => 30]
        );

        assertSameValue(200, $response->get_status());
        assertSameValue(
            2,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE id IN (%d, %d) AND unit_id = %d",
                    ...[...$ids, 30]
                )
            )
        );
    });

    test('06. Rutas bulk requieren permiso administrativo', function () use (
        $ids,
        $administratorId
    ): void {
        wp_set_current_user(0);

        try {
            $response = restRequest(
                'PATCH',
                '/veciahorra/v1/products/bulk/status',
                ['ids' => $ids, 'status' => 'inactive']
            );
        } finally {
            wp_set_current_user($administratorId);
        }

        assertTrue(
            in_array($response->get_status(), [401, 403], true),
            'La ruta bulk permitio acceso sin capability.'
        );
    });

    test('07. bulk/status no es capturado como ID individual', function () use (
        $ids
    ): void {
        $response = restRequest(
            'PATCH',
            '/veciahorra/v1/products/bulk/status',
            ['ids' => $ids, 'status' => 'inactive']
        );
        $body = $response->get_data();

        assertSameValue(200, $response->get_status());
        assertSameValue(2, $body['data']['requested'] ?? null);
        assertSameValue(2, $body['data']['affected'] ?? null);
    });

    test('08. Status individual funciona con ID numerico', function () use (
        $ids
    ): void {
        $response = restRequest(
            'PATCH',
            '/veciahorra/v1/products/' . $ids[0] . '/status',
            ['status' => 'active']
        );
        $body = $response->get_data();

        assertSameValue(200, $response->get_status());
        assertSameValue($ids[0], $body['data']['id'] ?? null);
        assertSameValue('active', $body['data']['status'] ?? null);
    });

    test('09. Status individual no acepta ID no numerico', function (): void {
        $response = restRequest(
            'PATCH',
            '/veciahorra/v1/products/abc/status',
            ['status' => 'active']
        );
        $body = $response->get_data();

        assertSameValue(404, $response->get_status());
        assertSameValue('rest_no_route', $body['code'] ?? null);
    });

    test('10. /products sigue funcionando', function (): void {
        $response = restRequest(
            'GET',
            '/veciahorra/v1/products',
            null,
            ['per_page' => '1']
        );

        assertSameValue(200, $response->get_status());
    });

    test('11. /products/search sigue funcionando', function () use (
        $suffix
    ): void {
        $response = restRequest(
            'GET',
            '/veciahorra/v1/products/search',
            null,
            ['term' => $suffix]
        );

        assertSameValue(200, $response->get_status());
    });

    test('12. /products/{id} sigue funcionando', function () use (
        $ids
    ): void {
        $response = restRequest(
            'GET',
            '/veciahorra/v1/products/' . $ids[0]
        );

        assertSameValue(200, $response->get_status());
    });

    $passed = 0;
    $failed = 0;

    foreach ($tests as [$name, $callback]) {
        try {
            $callback();
            $passed++;
            echo "PASS: {$name}", PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            echo "FAIL: {$name}", PHP_EOL;
            echo '      ', str_replace(
                PHP_EOL,
                PHP_EOL . '      ',
                $exception->getMessage()
            ), PHP_EOL;
        }
    }

    echo PHP_EOL;
    echo sprintf(
        'Resultado: %d aprobadas, %d fallidas, %d totales.',
        $passed,
        $failed,
        count($tests)
    ), PHP_EOL;

    $exitCode = $failed === 0 ? 0 : 1;
} finally {
    $wpdb->query('ROLLBACK');
}

exit($exitCode);
