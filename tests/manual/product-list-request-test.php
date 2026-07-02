<?php

declare(strict_types=1);

use VeciAhorra\Modules\Products\Requests\ProductListRequest;

if (! function_exists('wp_unslash')) {
    function wp_unslash(string $value): string
    {
        return stripslashes($value);
    }
}

require_once dirname(__DIR__, 2)
    . '/app/Modules/Products/Requests/ProductListRequest.php';

$tests = [];

function test(string $name, callable $callback): void
{
    global $tests;

    $tests[] = [$name, $callback];
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

function assertInvalid(array $input): ProductListRequest
{
    $request = new ProductListRequest($input);

    try {
        $request->validated();
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

$defaults = [
    'page' => 1,
    'per_page' => 20,
    'term' => null,
    'status' => null,
    'order_by' => 'name',
    'direction' => 'ASC',
];

test('01. Entrada vacia usa defaults completos', function () use ($defaults): void {
    $request = new ProductListRequest([]);

    assertSameValue($defaults, $request->validated());
    assertSameValue([], $request->errors());
});

test('02. page valido', function (): void {
    $result = (new ProductListRequest(['page' => '7']))->validated();

    assertSameValue(7, $result['page']);
});

test('03. page igual a cero', fn () => assertInvalid(['page' => 0]));
test('04. page negativo', fn () => assertInvalid(['page' => -1]));
test('05. page decimal', fn () => assertInvalid(['page' => '1.5']));
test('06. page string no numerico', fn () => assertInvalid(['page' => 'uno']));

test('07. page con overflow', function (): void {
    assertInvalid([
        'page' => decimalIncrement((string) PHP_INT_MAX),
    ]);
});

test('08. per_page igual a 100', function (): void {
    $result = (new ProductListRequest(['per_page' => 100]))->validated();

    assertSameValue(100, $result['per_page']);
});

test('09. per_page igual a 101', fn () => assertInvalid(['per_page' => 101]));

test('10. term elimina espacios exteriores', function (): void {
    $result = (new ProductListRequest(['term' => '  leche  ']))->validated();

    assertSameValue('leche', $result['term']);
});

test('11. term vacio despues de trim', function (): void {
    $result = (new ProductListRequest(['term' => " \t\n "]))->validated();

    assertSameValue(null, $result['term']);
});

test('12. status active', function (): void {
    $result = (new ProductListRequest(['status' => 'active']))->validated();

    assertSameValue('active', $result['status']);
});

test('13. status ACTIVE se normaliza', function (): void {
    $result = (new ProductListRequest(['status' => ' ACTIVE ']))->validated();

    assertSameValue('active', $result['status']);
});

test('14. status invalido', fn () => assertInvalid(['status' => 'draft']));

test('15. order_by name', function (): void {
    $result = (new ProductListRequest(['order_by' => 'name']))->validated();

    assertSameValue('name', $result['order_by']);
});

test('16. order_by NAME es invalido', fn () => assertInvalid(['order_by' => 'NAME']));

test('17. direction asc se normaliza', function (): void {
    $result = (new ProductListRequest(['direction' => 'asc']))->validated();

    assertSameValue('ASC', $result['direction']);
});

test('18. direction DESC', function (): void {
    $result = (new ProductListRequest(['direction' => 'DESC']))->validated();

    assertSameValue('DESC', $result['direction']);
});

test('19. direction invalida', fn () => assertInvalid(['direction' => 'UP']));

$invalidTypes = [
    'array' => [],
    'booleano' => true,
    'null' => null,
];

foreach ($invalidTypes as $type => $value) {
    test(
        sprintf('20. page rechaza %s', $type),
        fn () => assertInvalid(['page' => $value])
    );

    test(
        sprintf('20. per_page rechaza %s', $type),
        fn () => assertInvalid(['per_page' => $value])
    );

    test(
        sprintf('20. term rechaza %s', $type),
        fn () => assertInvalid(['term' => $value])
    );

    test(
        sprintf('20. status rechaza %s', $type),
        fn () => assertInvalid(['status' => $value])
    );

    test(
        sprintf('20. order_by rechaza %s', $type),
        fn () => assertInvalid(['order_by' => $value])
    );

    test(
        sprintf('20. direction rechaza %s', $type),
        fn () => assertInvalid(['direction' => $value])
    );
}

test('21. Parametros desconocidos son ignorados', function () use ($defaults): void {
    $request = new ProductListRequest([
        'unknown' => ['cualquier', 'valor'],
        'another_unknown' => true,
    ]);

    assertSameValue($defaults, $request->validated());
});

test('22. Misma instancia despues de fallo y exito', function () use ($defaults): void {
    $request = assertInvalid([
        'page' => 0,
        'direction' => 'UP',
    ]);

    assertSameValue(2, count($request->errors()));

    $reflection = new ReflectionClass($request);
    $input = $reflection->getProperty('input');
    $input->setValue($request, []);

    assertSameValue($defaults, $request->validated());
    assertSameValue([], $request->errors());
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
