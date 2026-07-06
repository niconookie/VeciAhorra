<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Products\Controllers\ProductController;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;
use VeciAhorra\Modules\Products\Services\ProductService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

$tests = [];

function test(string $name, callable $callback): void
{
    global $tests;
    $tests[] = [$name, $callback];
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

function controllerWithRepository(
    ProductRepository $repository
): ProductController {
    $service = new ProductService();
    $serviceReflection = new ReflectionClass($service);
    $repositoryProperty = $serviceReflection->getProperty('repository');
    $repositoryProperty->setValue($service, $repository);

    return new ProductController($service);
}

global $wpdb;

$controller = new ProductController(new ProductService());
$table = $wpdb->prefix . Config::TABLE_PREFIX . 'products';
$transactionStarted = $wpdb->query('START TRANSACTION');

if ($transactionStarted === false) {
    throw new RuntimeException(
        'No fue posible iniciar la transaccion de prueba.'
    );
}

try {
    $suffix = str_replace('.', '', uniqid('bulkcontroller', true));
    $now = '2020-01-01 00:00:00';
    $ids = [];
    $registeredTaxonomies = [];
    $catalogIds = [];

    foreach (
        ['product_cat', 'product_brand', 'pa_unidad']
        as $taxonomy
    ) {
        if (! taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, 'post');
            $registeredTaxonomies[] = $taxonomy;
        }

        $term = wp_insert_term(
            sprintf('Bulk controller %s %s', $taxonomy, $suffix),
            $taxonomy
        );

        if (is_wp_error($term)) {
            throw new RuntimeException($term->get_error_message());
        }

        $catalogIds[$taxonomy] = (int) $term['term_id'];
    }

    for ($index = 1; $index <= 2; $index++) {
        $inserted = $wpdb->insert(
            $table,
            [
                'name' => sprintf('Producto bulk controller %d', $index),
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

    test('01. bulkUpdateStatus valido', function () use (
        $controller,
        $ids,
        $table,
        $wpdb
    ): void {
        $result = $controller->bulkUpdateStatus([
            'ids' => $ids,
            'status' => 'active',
        ]);

        assertSameValue(
            [
                'success' => true,
                'data' => ['requested' => 2, 'affected' => 2],
            ],
            $result
        );
        assertSameValue(
            2,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE id IN (%d, %d) AND status = %s",
                    ...[...$ids, 'active']
                )
            )
        );
    });

    test('02. bulkUpdateCategory valido', function () use (
        $controller,
        $ids,
        $table,
        $wpdb,
        $catalogIds
    ): void {
        $categoryId = $catalogIds['product_cat'];
        $result = $controller->bulkUpdateCategory([
            'ids' => $ids,
            'category_id' => $categoryId,
        ]);

        assertSameValue(2, $result['data']['requested'] ?? null);
        assertSameValue(2, $result['data']['affected'] ?? null);
        assertSameValue(
            2,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE id IN (%d, %d) AND category_id = %d",
                    ...[...$ids, $categoryId]
                )
            )
        );
    });

    test('03. bulkUpdateBrand valido', function () use (
        $controller,
        $ids,
        $table,
        $wpdb,
        $catalogIds
    ): void {
        $brandId = $catalogIds['product_brand'];
        $result = $controller->bulkUpdateBrand([
            'ids' => $ids,
            'brand_id' => $brandId,
        ]);

        assertSameValue(2, $result['data']['affected'] ?? null);
        assertSameValue(
            2,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE id IN (%d, %d) AND brand_id = %d",
                    ...[...$ids, $brandId]
                )
            )
        );
    });

    test('04. bulkUpdateUnit valido', function () use (
        $controller,
        $ids,
        $table,
        $wpdb,
        $catalogIds
    ): void {
        $unitId = $catalogIds['pa_unidad'];
        $result = $controller->bulkUpdateUnit([
            'ids' => $ids,
            'unit_id' => $unitId,
        ]);

        assertSameValue(2, $result['data']['affected'] ?? null);
        assertSameValue(
            2,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE id IN (%d, %d) AND unit_id = %d",
                    ...[...$ids, $unitId]
                )
            )
        );
    });

    foreach ([
        ['bulkUpdateCategory', 'category_id'],
        ['bulkUpdateBrand', 'brand_id'],
        ['bulkUpdateUnit', 'unit_id'],
    ] as [$method, $field]) {
        test(
            sprintf('05. %s acepta null', $method),
            function () use ($controller, $ids, $method, $field): void {
                $result = $controller->{$method}([
                    'ids' => $ids,
                    $field => null,
                ]);

                assertSameValue(true, $result['success'] ?? null);
                assertSameValue(2, $result['data']['requested'] ?? null);
                assertSameValue(2, $result['data']['affected'] ?? null);
            }
        );
    }

    test('06. requested usa IDs unicos normalizados', function () use (
        $controller,
        $ids
    ): void {
        $result = $controller->bulkUpdateStatus([
            'ids' => [$ids[0], (string) $ids[1], $ids[0]],
            'status' => 'inactive',
        ]);

        assertSameValue(2, $result['data']['requested'] ?? null);
        assertSameValue(2, $result['data']['affected'] ?? null);
    });

    test('07. Request invalido retorna validation_error', function () use (
        $controller
    ): void {
        $result = $controller->bulkUpdateStatus([
            'ids' => [],
            'status' => 'draft',
        ]);

        assertSameValue(false, $result['success'] ?? null);
        assertSameValue(
            'validation_error',
            $result['error']['code'] ?? null
        );
    });

    test('08. PersistenceException retorna persistence_error', function () use (
        $ids,
        $wpdb
    ): void {
        $repository = new ProductRepository();
        $repositoryReflection = new ReflectionClass($repository);
        $tableProperty = $repositoryReflection->getProperty('table');
        $tableProperty->setValue(
            $repository,
            'missing_products_' . uniqid()
        );
        $brokenController = controllerWithRepository($repository);
        $showErrors = $wpdb->show_errors;
        $wpdb->hide_errors();

        try {
            $result = $brokenController->bulkUpdateStatus([
                'ids' => $ids,
                'status' => 'active',
            ]);
        } finally {
            if ($showErrors) {
                $wpdb->show_errors();
            }
        }

        assertSameValue(false, $result['success'] ?? null);
        assertSameValue(
            'persistence_error',
            $result['error']['code'] ?? null
        );
    });

    test('09. Throwable desconocido retorna internal_error', function () use (
        $ids
    ): void {
        $repositoryReflection = new ReflectionClass(
            ProductRepository::class
        );
        $repository = $repositoryReflection->newInstanceWithoutConstructor();
        $brokenController = controllerWithRepository($repository);
        $result = $brokenController->bulkUpdateStatus([
            'ids' => $ids,
            'status' => 'active',
        ]);

        assertSameValue(false, $result['success'] ?? null);
        assertSameValue(
            'internal_error',
            $result['error']['code'] ?? null
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

    foreach ($registeredTaxonomies ?? [] as $taxonomy) {
        unregister_taxonomy($taxonomy);
    }
}

exit($exitCode);
