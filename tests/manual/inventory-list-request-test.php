<?php

declare(strict_types=1);

use VeciAhorra\Modules\Inventory\Requests\InventoryListRequest;

if (! function_exists('wp_unslash')) {
    function wp_unslash(string $value): string
    {
        return stripslashes($value);
    }
}

require_once dirname(__DIR__, 2)
    . '/app/Modules/Inventory/Requests/InventoryListRequest.php';

function assertListSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertListInvalid(array $input): void
{
    $request = new InventoryListRequest($input);

    try {
        $request->validated();
    } catch (InvalidArgumentException) {
        if ($request->errors() !== []) {
            return;
        }
    }

    throw new RuntimeException('Se esperaba InvalidArgumentException.');
}

$defaults = [
    'page' => 1,
    'per_page' => 20,
    'search' => null,
    'product_id' => null,
    'minimarket_id' => null,
    'status' => null,
];

assertListSame($defaults, (new InventoryListRequest([]))->validated());
assertListSame(
    [
        'page' => 2,
        'per_page' => 100,
        'search' => 'leche',
        'product_id' => 10,
        'minimarket_id' => 20,
        'status' => 'inactive',
    ],
    (new InventoryListRequest([
        'page' => '2',
        'per_page' => 100,
        'search' => '  leche  ',
        'product_id' => '10',
        'minimarket_id' => 20,
        'status' => ' INACTIVE ',
    ]))->validated()
);
assertListSame(
    null,
    (new InventoryListRequest(['search' => '  ']))
        ->validated()['search']
);
assertListSame(
    null,
    (new InventoryListRequest(['product_id' => '']))
        ->validated()['product_id']
);

foreach (
    [
        ['page' => 0],
        ['per_page' => 101],
        ['search' => []],
        ['product_id' => -1],
        ['minimarket_id' => '1.5'],
        ['status' => 'draft'],
        ['product_id' => ((string) PHP_INT_MAX) . '0'],
    ] as $input
) {
    assertListInvalid($input);
}

echo "PASS inventory-list-request-test\n";
