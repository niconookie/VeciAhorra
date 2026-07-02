<?php

declare(strict_types=1);

use VeciAhorra\Modules\Products\Requests\ProductBulkRequest;

if (! function_exists('wp_unslash')) {
    function wp_unslash(string $value): string
    {
        return stripslashes($value);
    }
}

require_once dirname(__DIR__, 2)
    . '/app/Modules/Products/Requests/ProductBulkRequest.php';

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

function assertInvalid(
    array $input,
    string $method
): ProductBulkRequest {
    $request = new ProductBulkRequest($input);

    try {
        $request->{$method}();
    } catch (InvalidArgumentException) {
        if ($request->errors() === []) {
            throw new RuntimeException(
                'La excepcion no dejo errores disponibles.'
            );
        }

        return $request;
    }

    throw new RuntimeException(
        'Se esperaba InvalidArgumentException.'
    );
}

function decimalIncrement(string $value): string
{
    $digits = str_split($value);
    $carry = 1;

    for ($index = count($digits) - 1; $index >= 0; $index--) {
        $digit = (int) $digits[$index] + $carry;
        $digits[$index] = (string) ($digit % 10);
        $carry = intdiv($digit, 10);

        if ($carry === 0) {
            break;
        }
    }

    if ($carry === 1) {
        array_unshift($digits, '1');
    }

    return implode('', $digits);
}

test('01. Status valido y normalizado', function (): void {
    $result = (new ProductBulkRequest([
        'ids' => ['1', 2],
        'status' => ' ACTIVE ',
    ]))->validateForStatus();

    assertSameValue(
        ['ids' => [1, 2], 'status' => 'active'],
        $result
    );
});

test('02. Categoria valida', function (): void {
    $result = (new ProductBulkRequest([
        'ids' => [1],
        'category_id' => '10',
    ]))->validateForCategory();

    assertSameValue(['ids' => [1], 'category_id' => 10], $result);
});

test('03. Marca valida', function (): void {
    $result = (new ProductBulkRequest([
        'ids' => [1],
        'brand_id' => 20,
    ]))->validateForBrand();

    assertSameValue(['ids' => [1], 'brand_id' => 20], $result);
});

test('04. Unidad valida', function (): void {
    $result = (new ProductBulkRequest([
        'ids' => [1],
        'unit_id' => '30',
    ]))->validateForUnit();

    assertSameValue(['ids' => [1], 'unit_id' => 30], $result);
});

foreach ([
    'category_id' => 'validateForCategory',
    'brand_id' => 'validateForBrand',
    'unit_id' => 'validateForUnit',
] as $field => $method) {
    test(
        sprintf('05. %s acepta null', $field),
        function () use ($field, $method): void {
            $result = (new ProductBulkRequest([
                'ids' => [1],
                $field => null,
            ]))->{$method}();

            assertSameValue(
                ['ids' => [1], $field => null],
                $result
            );
        }
    );
}

test('06. IDs duplicados se normalizan preservando orden', function (): void {
    $result = (new ProductBulkRequest([
        'ids' => [3, '1', 3, '01', 2, 1],
        'status' => 'inactive',
    ]))->validateForStatus();

    assertSameValue([3, 1, 2], $result['ids']);
});

test('07. IDs vacio', fn () => assertInvalid(
    ['ids' => [], 'status' => 'active'],
    'validateForStatus'
));

test('08. IDs ausente', fn () => assertInvalid(
    ['status' => 'active'],
    'validateForStatus'
));

$invalidIds = [
    'cero' => 0,
    'negativo' => -1,
    'decimal' => '1.5',
    'booleano' => true,
    'null' => null,
    'array' => [1],
    'string no numerico' => 'uno',
];

foreach ($invalidIds as $label => $value) {
    test(
        sprintf('09. ID %s es invalido', $label),
        fn () => assertInvalid(
            ['ids' => [$value], 'status' => 'active'],
            'validateForStatus'
        )
    );
}

test('10. ID con overflow', function (): void {
    assertInvalid(
        [
            'ids' => [decimalIncrement((string) PHP_INT_MAX)],
            'status' => 'active',
        ],
        'validateForStatus'
    );
});

test('11. Mas de 1000 IDs', function (): void {
    assertInvalid(
        ['ids' => range(1, 1001), 'status' => 'active'],
        'validateForStatus'
    );
});

test('12. Status invalido', fn () => assertInvalid(
    ['ids' => [1], 'status' => 'draft'],
    'validateForStatus'
));

test('13. Status ausente', fn () => assertInvalid(
    ['ids' => [1]],
    'validateForStatus'
));

foreach ([
    'category_id' => 'validateForCategory',
    'brand_id' => 'validateForBrand',
    'unit_id' => 'validateForUnit',
] as $field => $method) {
    test(
        sprintf('14. %s ausente', $field),
        fn () => assertInvalid(['ids' => [1]], $method)
    );
}

$invalidRelations = [
    ['category_id', 'validateForCategory', 0],
    ['category_id', 'validateForCategory', -1],
    ['brand_id', 'validateForBrand', '2.5'],
    ['brand_id', 'validateForBrand', false],
    ['unit_id', 'validateForUnit', []],
    ['unit_id', 'validateForUnit', 'unidad'],
];

foreach ($invalidRelations as [$field, $method, $value]) {
    test(
        sprintf('15. %s rechaza valor invalido', $field),
        fn () => assertInvalid(
            ['ids' => [1], $field => $value],
            $method
        )
    );
}

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
