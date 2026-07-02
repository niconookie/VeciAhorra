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
    if ($expected === $actual) {
        return;
    }

    throw new RuntimeException(sprintf(
        "Esperado: %s\nRecibido: %s",
        var_export($expected, true),
        var_export($actual, true)
    ));
}

function getRequest(string $route): WP_REST_Response
{
    $request = new WP_REST_Request('GET', $route);

    return rest_do_request($request);
}

$routesFile = dirname(__DIR__, 2)
    . '/app/Modules/Products/Routes/ProductRoutes.php';
$routesSource = file_get_contents($routesFile);

assertTrue(
    is_string($routesSource),
    'No fue posible leer ProductRoutes.php.'
);

test('01. La ruta individual usa patron numerico', function () use ($routesSource): void {
    assertTrue(
        str_contains(
            $routesSource,
            "self::RESOURCE . '/(?P<id>\\d+)'"
        ),
        'No se encontro el patron numerico de la ruta individual.'
    );
});

test('02. ProductRoutes::show delega al Controller', function () use ($routesSource): void {
    $matched = preg_match(
        '/public function show\s*\([^)]*\).*?controller->show\s*\(/s',
        $routesSource
    );

    assertSameValue(1, $matched);
});

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
    $suffix = str_replace('.', '', uniqid('detail-test-', true));
    $now = current_time('mysql');
    $inserted = $wpdb->insert(
        $table,
        [
            'name' => 'Producto prueba detalle',
            'slug' => $suffix,
            'sku' => strtoupper($suffix),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        ['%s', '%s', '%s', '%s', '%s', '%s']
    );

    assertSameValue(1, $inserted);

    $productId = (int) $wpdb->insert_id;
    assertTrue($productId > 0, 'La fixture no obtuvo un ID valido.');

    test('03. ID existente retorna producto serializado', function () use ($productId): void {
        $response = getRequest(
            '/veciahorra/v1/products/' . $productId
        );
        $body = $response->get_data();

        assertSameValue(200, $response->get_status());
        assertTrue(is_array($body), 'La respuesta debe ser un array.');
        assertSameValue(true, $body['success'] ?? null);
        assertTrue(
            is_array($body['data'] ?? null),
            'El producto debe estar serializado como array.'
        );
        assertSameValue(
            $productId,
            (int) ($body['data']['id'] ?? 0)
        );
        assertSameValue(
            'Producto prueba detalle',
            $body['data']['name'] ?? null
        );
    });

    test('04. ID inexistente retorna product_not_found', function (): void {
        $response = getRequest(
            '/veciahorra/v1/products/' . PHP_INT_MAX
        );
        $body = $response->get_data();

        assertSameValue(404, $response->get_status());
        assertSameValue(false, $body['success'] ?? null);
        assertSameValue(
            'product_not_found',
            $body['error']['code'] ?? null
        );
    });

    test('05. ID no numerico no coincide con la ruta', function (): void {
        $response = getRequest(
            '/veciahorra/v1/products/no-numerico'
        );
        $body = $response->get_data();

        assertSameValue(404, $response->get_status());
        assertSameValue('rest_no_route', $body['code'] ?? null);
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
