<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Products\Services\ProductService;

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

function assertInvalid(callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException(
        'Se esperaba InvalidArgumentException.'
    );
}

global $wpdb;

$service = new ProductService();
$table = $wpdb->prefix . Config::TABLE_PREFIX . 'products';
$transactionStarted = $wpdb->query('START TRANSACTION');

assertTrue(
    $transactionStarted !== false,
    'No fue posible iniciar la transaccion de prueba.'
);

try {
    $suffix = str_replace('.', '', uniqid('bulkservice', true));
    $initialUpdatedAt = '2020-01-01 00:00:00';
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
            sprintf('Bulk service %s %s', $taxonomy, $suffix),
            $taxonomy
        );

        assertTrue(! is_wp_error($term), 'No fue posible crear el termino.');
        $catalogIds[$taxonomy] = (int) $term['term_id'];
    }

    for ($index = 1; $index <= 2; $index++) {
        $inserted = $wpdb->insert(
            $table,
            [
                'name' => sprintf('Producto bulk service %d', $index),
                'slug' => sprintf('%s-%d', $suffix, $index),
                'sku' => sprintf('%s-%d', strtoupper($suffix), $index),
                'status' => 'draft',
                'created_at' => $initialUpdatedAt,
                'updated_at' => $initialUpdatedAt,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        assertSameValue(1, $inserted);
        $ids[] = (int) $wpdb->insert_id;
    }

    test('01. Status masivo valido delega al Repository', function () use (
        $service,
        $ids,
        $table,
        $wpdb
    ): void {
        assertSameValue(2, $service->bulkUpdateStatus($ids, 'active'));
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

    test('02. Categoria masiva valida', function () use (
        $service,
        $ids,
        $table,
        $wpdb,
        $catalogIds
    ): void {
        $categoryId = $catalogIds['product_cat'];
        assertSameValue(2, $service->bulkUpdateCategory($ids, $categoryId));
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

    test('03. Marca masiva valida', function () use (
        $service,
        $ids,
        $table,
        $wpdb,
        $catalogIds
    ): void {
        $brandId = $catalogIds['product_brand'];
        assertSameValue(2, $service->bulkUpdateBrand($ids, $brandId));
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

    test('04. Unidad masiva valida', function () use (
        $service,
        $ids,
        $table,
        $wpdb,
        $catalogIds
    ): void {
        $unitId = $catalogIds['pa_unidad'];
        assertSameValue(2, $service->bulkUpdateUnit($ids, $unitId));
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

    test('05. Categoria acepta null', function () use ($service, $ids): void {
        assertSameValue(2, $service->bulkUpdateCategory($ids, null));
    });

    test('06. Marca acepta null', function () use ($service, $ids): void {
        assertSameValue(2, $service->bulkUpdateBrand($ids, null));
    });

    test('07. Unidad acepta null', function () use ($service, $ids): void {
        assertSameValue(2, $service->bulkUpdateUnit($ids, null));
    });

    test('08. IDs vacio produce InvalidArgumentException', function () use (
        $service
    ): void {
        assertInvalid(
            fn () => $service->bulkUpdateStatus([], 'active')
        );
    });

    test('09. Status invalido produce InvalidArgumentException', function () use (
        $service,
        $ids
    ): void {
        assertInvalid(
            fn () => $service->bulkUpdateStatus($ids, 'draft')
        );
    });

    foreach ([
        ['bulkUpdateCategory', 0],
        ['bulkUpdateBrand', -1],
        ['bulkUpdateUnit', 0],
    ] as [$method, $value]) {
        test(
            sprintf('10. %s rechaza ID relacional invalido', $method),
            function () use ($service, $ids, $method, $value): void {
                assertInvalid(
                    fn () => $service->{$method}($ids, $value)
                );
            }
        );
    }

    test('11. updated_at es un string MySQL valido', function () use (
        $ids,
        $table,
        $wpdb,
        $initialUpdatedAt
    ): void {
        $values = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT updated_at FROM {$table} WHERE id IN (%d, %d)",
                ...$ids
            )
        );

        assertSameValue(2, count($values));

        foreach ($values as $updatedAt) {
            assertTrue(
                is_string($updatedAt)
                    && preg_match(
                        '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
                        $updatedAt
                    ) === 1,
                'updated_at no tiene formato MySQL valido.'
            );
            assertTrue(
                $updatedAt !== $initialUpdatedAt,
                'updated_at no fue actualizado.'
            );
        }
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
