<?php

declare(strict_types=1);

use VeciAhorra\Exceptions\CatalogUnavailableException;
use VeciAhorra\Modules\ProductCatalogs\Controllers\CatalogController;
use VeciAhorra\Modules\ProductCatalogs\Repositories\TaxonomyCatalogRepository;
use VeciAhorra\Modules\ProductCatalogs\Services\CatalogService;

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

/**
 * @param array<string, mixed> $body
 */
function assertCatalogContract(array $body): void
{
    assertSameValue(true, $body['success'] ?? null);
    assertTrue(is_array($body['data'] ?? null), 'data debe ser un array.');

    $names = [];

    foreach ($body['data'] as $item) {
        assertTrue(is_array($item), 'Cada elemento debe ser un array.');
        assertTrue(
            is_int($item['id'] ?? null) && $item['id'] > 0,
            'Cada ID debe ser un entero positivo.'
        );
        assertTrue(
            is_string($item['name'] ?? null)
                && trim($item['name']) !== '',
            'Cada nombre debe ser texto no vacío.'
        );
        assertSameValue(
            ['id', 'name'],
            array_keys($item)
        );
        $names[] = $item['name'];
    }

    $sortedNames = $names;
    natcasesort($sortedNames);
    assertSameValue(
        array_values($sortedNames),
        $names
    );
}

$catalogs = [
    '/veciahorra/v1/categories' => 'product_cat',
    '/veciahorra/v1/brands' => 'product_brand',
    '/veciahorra/v1/units' => 'pa_unidad',
];

test('01. Las tres rutas están registradas', function () use ($catalogs): void {
    $routes = rest_get_server()->get_routes();

    foreach (array_keys($catalogs) as $route) {
        assertTrue(
            array_key_exists($route, $routes),
            "No se registró {$route}."
        );
    }
});

test('02. Catálogos y Products comparten permisos', function (): void {
    wp_set_current_user(0);

    $products = getRequest('/veciahorra/v1/products');
    $catalog = getRequest('/veciahorra/v1/categories');

    assertSameValue($products->get_status(), $catalog->get_status());
    assertTrue(
        in_array($catalog->get_status(), [401, 403], true),
        'Un usuario anónimo no debe administrar catálogos.'
    );
});

test('03. Los endpoints cumplen su contrato', function () use ($catalogs): void {
    $administratorIds = get_users([
        'role' => 'administrator',
        'number' => 1,
        'fields' => 'ids',
    ]);

    assertTrue(
        $administratorIds !== [],
        'La prueba requiere un usuario administrador.'
    );

    wp_set_current_user((int) $administratorIds[0]);

    foreach ($catalogs as $route => $taxonomy) {
        $response = getRequest($route);
        $body = $response->get_data();

        assertTrue(is_array($body), 'La respuesta debe ser un array.');

        if (! taxonomy_exists($taxonomy)) {
            assertSameValue(503, $response->get_status());
            assertSameValue(false, $body['success'] ?? null);
            assertSameValue(
                'catalog_unavailable',
                $body['error']['code'] ?? null
            );
            assertSameValue(
                'No fue posible cargar el catálogo.',
                $body['error']['message'] ?? null
            );
            continue;
        }

        assertSameValue(200, $response->get_status());
        assertCatalogContract($body);
    }
});

test('04. Una taxonomía ausente produce error controlado', function (): void {
    $repository = new class extends TaxonomyCatalogRepository {
        protected function taxonomy(): string
        {
            return 'veciahorra_missing_catalog_test';
        }
    };
    $service = new class ($repository) extends CatalogService {
    };
    $controller = new class ($service) extends CatalogController {
    };

    $result = $controller->index();

    assertSameValue(false, $result['success'] ?? null);
    assertSameValue(
        'catalog_unavailable',
        $result['error']['code'] ?? null
    );

    try {
        $repository->all();
    } catch (CatalogUnavailableException $exception) {
        return;
    }

    throw new RuntimeException(
        'El Repository no detectó la taxonomía ausente.'
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

exit($failed === 0 ? 0 : 1);
