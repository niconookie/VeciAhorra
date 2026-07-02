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

function getRequest(
    string $route,
    array $query = []
): WP_REST_Response {
    $request = new WP_REST_Request('GET', $route);
    $request->set_query_params($query);

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

wp_set_current_user((int) $administratorIds[0]);

$table = $wpdb->prefix . Config::TABLE_PREFIX . 'products';
$transactionStarted = $wpdb->query('START TRANSACTION');

assertTrue(
    $transactionStarted !== false,
    'No fue posible iniciar la transaccion de prueba.'
);

try {
    $suffix = str_replace('.', '', uniqid('searchtest', true));
    $common = 'common-' . $suffix;
    $now = current_time('mysql');
    $fixtures = [
        [
            'name' => 'Nombre-' . $suffix . ' ' . $common,
            'slug' => 'slug-name-' . $suffix,
            'sku' => 'SKU-NAME-' . strtoupper($suffix),
        ],
        [
            'name' => 'Producto SKU ' . $common,
            'slug' => 'slug-sku-' . $suffix,
            'sku' => 'SPECIAL-SKU-' . strtoupper($suffix),
        ],
        [
            'name' => 'Producto Slug ' . $common,
            'slug' => 'special-slug-' . $suffix,
            'sku' => 'SKU-SLUG-' . strtoupper($suffix),
        ],
    ];
    $ids = [];

    foreach ($fixtures as $fixture) {
        $inserted = $wpdb->insert(
            $table,
            $fixture + [
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        assertSameValue(1, $inserted);
        $ids[] = (int) $wpdb->insert_id;
    }

    test('01. La ruta /products/search existe', function (): void {
        $routes = rest_get_server()->get_routes();

        assertTrue(
            array_key_exists('/veciahorra/v1/products/search', $routes),
            'La ruta de busqueda no esta registrada.'
        );
    });

    test('02. Busqueda por nombre', function () use ($suffix): void {
        $response = getRequest(
            '/veciahorra/v1/products/search',
            ['term' => 'Nombre-' . $suffix]
        );
        $body = $response->get_data();

        assertSameValue(200, $response->get_status());
        assertSameValue(1, count($body['data'] ?? []));
        assertSameValue(
            'Nombre-' . $suffix . ' common-' . $suffix,
            $body['data'][0]['name'] ?? null
        );
    });

    test('03. Busqueda por SKU', function () use ($suffix): void {
        $response = getRequest(
            '/veciahorra/v1/products/search',
            ['term' => 'SPECIAL-SKU-' . strtoupper($suffix)]
        );
        $body = $response->get_data();

        assertSameValue(200, $response->get_status());
        assertSameValue(1, count($body['data'] ?? []));
        assertSameValue(
            'SPECIAL-SKU-' . strtoupper($suffix),
            $body['data'][0]['sku'] ?? null
        );
    });

    test('04. Busqueda por slug', function () use ($suffix): void {
        $response = getRequest(
            '/veciahorra/v1/products/search',
            ['term' => 'special-slug-' . $suffix]
        );
        $body = $response->get_data();

        assertSameValue(200, $response->get_status());
        assertSameValue(1, count($body['data'] ?? []));
        assertSameValue(
            'special-slug-' . $suffix,
            $body['data'][0]['slug'] ?? null
        );
    });

    test('05. Busqueda sin resultados', function () use ($suffix): void {
        $response = getRequest(
            '/veciahorra/v1/products/search',
            ['term' => 'missing-' . $suffix]
        );
        $body = $response->get_data();

        assertSameValue(200, $response->get_status());
        assertSameValue([], $body['data'] ?? null);
        assertSameValue(0, $body['meta']['total'] ?? null);
    });

    test('06. Paginacion de busqueda', function () use ($common): void {
        $response = getRequest(
            '/veciahorra/v1/products/search',
            [
                'term' => $common,
                'page' => '2',
                'per_page' => '2',
                'order_by' => 'id',
                'direction' => 'ASC',
            ]
        );
        $body = $response->get_data();

        assertSameValue(200, $response->get_status());
        assertSameValue(1, count($body['data'] ?? []));
        assertSameValue(2, $body['meta']['page'] ?? null);
        assertSameValue(2, $body['meta']['per_page'] ?? null);
        assertSameValue(3, $body['meta']['total'] ?? null);
        assertSameValue(2, $body['meta']['total_pages'] ?? null);
    });

    test('07. Query invalida retorna validation_error', function (): void {
        $response = getRequest(
            '/veciahorra/v1/products/search',
            ['page' => '0']
        );
        $body = $response->get_data();

        assertSameValue(422, $response->get_status());
        assertSameValue(
            'validation_error',
            $body['error']['code'] ?? null
        );
    });

    test('08. No rompe /products', function (): void {
        $response = getRequest(
            '/veciahorra/v1/products',
            ['per_page' => '1']
        );
        $body = $response->get_data();

        assertSameValue(200, $response->get_status());
        assertSameValue(true, $body['success'] ?? null);
        assertTrue(is_array($body['data'] ?? null), 'data debe ser array.');
        assertTrue(is_array($body['meta'] ?? null), 'meta debe ser array.');
    });

    test('09. No rompe /products/{id}', function () use ($ids): void {
        $response = getRequest(
            '/veciahorra/v1/products/' . $ids[0]
        );
        $body = $response->get_data();

        assertSameValue(200, $response->get_status());
        assertSameValue(true, $body['success'] ?? null);
        assertSameValue(
            $ids[0],
            (int) ($body['data']['id'] ?? 0)
        );
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
