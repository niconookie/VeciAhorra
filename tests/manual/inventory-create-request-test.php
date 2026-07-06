<?php

declare(strict_types=1);

use VeciAhorra\Modules\Inventory\Requests\InventoryCreateRequest;

if (! function_exists('wp_unslash')) {
    function wp_unslash(string $value): string
    {
        return stripslashes($value);
    }
}

require_once dirname(__DIR__, 2)
    . '/app/Modules/Inventory/Requests/InventoryCreateRequest.php';

function assertCreateSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertCreateInvalid(array $input): void
{
    $request = new InventoryCreateRequest($input);

    try {
        $request->validated();
    } catch (InvalidArgumentException) {
        if ($request->errors() !== []) {
            return;
        }
    }

    throw new RuntimeException('Se esperaba InvalidArgumentException.');
}

assertCreateSame(
    [
        'product_id' => 10,
        'minimarket_id' => 20,
        'price' => 1290.5,
        'stock' => 0,
        'status' => 'active',
    ],
    (new InventoryCreateRequest([
        'product_id' => '10',
        'minimarket_id' => 20,
        'price' => '1290.50',
    ]))->validated()
);
assertCreateSame(
    [
        'product_id' => 1,
        'minimarket_id' => 2,
        'price' => 0.0,
        'stock' => 15,
        'status' => 'inactive',
    ],
    (new InventoryCreateRequest([
        'product_id' => 1,
        'minimarket_id' => 2,
        'price' => 0,
        'stock' => '15',
        'status' => ' INACTIVE ',
    ]))->validated()
);

foreach (
    [
        [],
        ['product_id' => 1, 'minimarket_id' => 2],
        ['product_id' => 0, 'minimarket_id' => 2, 'price' => 1],
        ['product_id' => 1, 'minimarket_id' => false, 'price' => 1],
        ['product_id' => 1, 'minimarket_id' => 2, 'price' => -0.01],
        ['product_id' => 1, 'minimarket_id' => 2, 'price' => 'precio'],
        ['product_id' => 1, 'minimarket_id' => 2, 'price' => 1, 'stock' => -1],
        ['product_id' => 1, 'minimarket_id' => 2, 'price' => 1, 'stock' => '1.5'],
        ['product_id' => 1, 'minimarket_id' => 2, 'price' => 1, 'status' => 'draft'],
    ] as $input
) {
    assertCreateInvalid($input);
}

echo "PASS inventory-create-request-test\n";
