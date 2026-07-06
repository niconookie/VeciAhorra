<?php

declare(strict_types=1);

use VeciAhorra\Modules\Inventory\Requests\InventoryUpdateRequest;

if (! function_exists('wp_unslash')) {
    function wp_unslash(string $value): string
    {
        return stripslashes($value);
    }
}

require_once dirname(__DIR__, 2)
    . '/app/Modules/Inventory/Requests/InventoryUpdateRequest.php';

function assertUpdateSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertUpdateInvalid(array $input): void
{
    $request = new InventoryUpdateRequest($input);

    try {
        $request->validated();
    } catch (InvalidArgumentException) {
        if ($request->errors() !== []) {
            return;
        }
    }

    throw new RuntimeException('Se esperaba InvalidArgumentException.');
}

assertUpdateSame(
    ['price' => 999.9],
    (new InventoryUpdateRequest(['price' => '999.90']))->validated()
);
assertUpdateSame(
    ['stock' => 0, 'status' => 'inactive'],
    (new InventoryUpdateRequest([
        'stock' => '0',
        'status' => ' INACTIVE ',
    ]))->validated()
);

foreach (
    [
        [],
        ['unknown' => true],
        ['product_id' => 1],
        ['minimarket_id' => 2, 'price' => 10],
        ['price' => -1],
        ['price' => null],
        ['stock' => -1],
        ['stock' => '2.5'],
        ['status' => 'draft'],
        ['status' => false],
    ] as $input
) {
    assertUpdateInvalid($input);
}

echo "PASS inventory-update-request-test\n";
