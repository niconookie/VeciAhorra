<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Products\Repositories\ProductRepository;

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

global $wpdb;

$repository = new ProductRepository();
$table = $wpdb->prefix . Config::TABLE_PREFIX . 'products';
$transactionStarted = $wpdb->query('START TRANSACTION');

assertTrue(
    $transactionStarted !== false,
    'No fue posible iniciar la transaccion de prueba.'
);

try {
    $suffix = str_replace('.', '', uniqid('bulkrepo', true));
    $initialUpdatedAt = '2020-01-01 00:00:00';
    $ids = [];

    for ($index = 1; $index <= 3; $index++) {
        $inserted = $wpdb->insert(
            $table,
            [
                'name' => sprintf('Producto bulk repo %d', $index),
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

    test('01. Status masivo con multiples IDs', function () use (
        $repository,
        $ids,
        $table,
        $wpdb
    ): void {
        $updatedAt = '2021-01-01 00:00:00';
        $affected = $repository->bulkUpdateStatus(
            $ids,
            'active',
            $updatedAt
        );

        assertSameValue(3, $affected);
        assertSameValue(
            3,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE id IN (%d, %d, %d) AND status = %s",
                    ...[...$ids, 'active']
                )
            )
        );
    });

    test('02. Categoria masiva', function () use (
        $repository,
        $ids,
        $table,
        $wpdb
    ): void {
        assertSameValue(
            3,
            $repository->bulkUpdateCategory(
                $ids,
                10,
                '2022-01-01 00:00:00'
            )
        );
        assertSameValue(
            3,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE id IN (%d, %d, %d) AND category_id = %d",
                    ...[...$ids, 10]
                )
            )
        );
    });

    test('03. Marca masiva', function () use (
        $repository,
        $ids,
        $table,
        $wpdb
    ): void {
        assertSameValue(
            3,
            $repository->bulkUpdateBrand(
                $ids,
                20,
                '2023-01-01 00:00:00'
            )
        );
        assertSameValue(
            3,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE id IN (%d, %d, %d) AND brand_id = %d",
                    ...[...$ids, 20]
                )
            )
        );
    });

    test('04. Unidad masiva', function () use (
        $repository,
        $ids,
        $table,
        $wpdb
    ): void {
        assertSameValue(
            3,
            $repository->bulkUpdateUnit(
                $ids,
                30,
                '2024-01-01 00:00:00'
            )
        );
        assertSameValue(
            3,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE id IN (%d, %d, %d) AND unit_id = %d",
                    ...[...$ids, 30]
                )
            )
        );
    });

    test('05. Categoria acepta null', function () use (
        $repository,
        $ids,
        $table,
        $wpdb
    ): void {
        assertSameValue(
            3,
            $repository->bulkUpdateCategory(
                $ids,
                null,
                '2025-01-01 00:00:00'
            )
        );
        assertSameValue(
            3,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE id IN (%d, %d, %d) AND category_id IS NULL",
                    ...$ids
                )
            )
        );
    });

    test('06. Marca acepta null', function () use (
        $repository,
        $ids,
        $table,
        $wpdb
    ): void {
        assertSameValue(
            3,
            $repository->bulkUpdateBrand(
                $ids,
                null,
                '2026-01-01 00:00:00'
            )
        );
        assertSameValue(
            3,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE id IN (%d, %d, %d) AND brand_id IS NULL",
                    ...$ids
                )
            )
        );
    });

    test('07. Unidad acepta null', function () use (
        $repository,
        $ids,
        $table,
        $wpdb
    ): void {
        assertSameValue(
            3,
            $repository->bulkUpdateUnit(
                $ids,
                null,
                '2027-01-01 00:00:00'
            )
        );
        assertSameValue(
            3,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE id IN (%d, %d, %d) AND unit_id IS NULL",
                    ...$ids
                )
            )
        );
    });

    test('08. IDs inexistentes retornan cero', function () use ($repository): void {
        assertSameValue(
            0,
            $repository->bulkUpdateStatus(
                [PHP_INT_MAX],
                'inactive',
                '2028-01-01 00:00:00'
            )
        );
    });

    test('09. Mezcla de IDs existentes e inexistentes', function () use (
        $repository,
        $ids
    ): void {
        assertSameValue(
            1,
            $repository->bulkUpdateStatus(
                [$ids[0], PHP_INT_MAX],
                'inactive',
                '2029-01-01 00:00:00'
            )
        );
    });

    test('10. updated_at cambia', function () use (
        $ids,
        $table,
        $wpdb
    ): void {
        $updatedAt = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT updated_at FROM {$table} WHERE id = %d",
                $ids[0]
            )
        );

        assertSameValue('2029-01-01 00:00:00', $updatedAt);
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
