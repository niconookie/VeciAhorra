<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Requests\OrderRequest;

if (! function_exists('wp_unslash')) {
    function wp_unslash(string $value): string
    {
        return stripslashes($value);
    }
}

require_once dirname(__DIR__, 2)
    . '/app/Modules/Orders/Requests/OrderRequest.php';

function assertOrderRequestSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertOrderRequestInvalid(array $input): void
{
    $request = new OrderRequest($input);

    try {
        $request->validated();
    } catch (InvalidArgumentException) {
        if ($request->errors() !== []) {
            return;
        }
    }

    throw new RuntimeException('Se esperaba InvalidArgumentException.');
}

$valid = (new OrderRequest([
    'customer_id' => ' 1 ',
    'minimarket_id' => '2',
    'ignored_root' => 'fuera',
    'items' => [
        [
            'product_id' => '10',
            'inventory_id' => '25',
            'quantity' => '3',
            'unit_price' => 999,
            'ignored_item' => true,
        ],
        [
            'product_id' => 11,
            'inventory_id' => 26,
            'quantity' => 1,
        ],
    ],
]))->validated();

assertOrderRequestSame(
    [
        'customer_id' => 1,
        'minimarket_id' => 2,
        'items' => [
            [
                'product_id' => 10,
                'inventory_id' => 25,
                'quantity' => 3,
            ],
            [
                'product_id' => 11,
                'inventory_id' => 26,
                'quantity' => 1,
            ],
        ],
    ],
    $valid
);

$base = [
    'customer_id' => 1,
    'minimarket_id' => 2,
    'items' => [[
        'product_id' => 10,
        'inventory_id' => 25,
        'quantity' => 3,
    ]],
];

foreach (
    [
        array_replace($base, ['customer_id' => 0]),
        array_replace($base, ['customer_id' => '1.5']),
        array_replace($base, ['minimarket_id' => -1]),
        array_replace($base, ['minimarket_id' => true]),
        array_diff_key($base, ['items' => true]),
        array_replace($base, ['items' => []]),
        array_replace($base, ['items' => 'item']),
        array_replace($base, ['items' => ['no es objeto']]),
        array_replace_recursive($base, ['items' => [['product_id' => 0]]]),
        array_replace_recursive($base, ['items' => [['inventory_id' => '2.5']]]),
        array_replace_recursive($base, ['items' => [['quantity' => 0]]]),
        array_replace_recursive($base, ['items' => [['quantity' => false]]]),
        array_replace_recursive($base, ['items' => [[
            'product_id' => ((string) PHP_INT_MAX) . '0',
        ]]]),
    ] as $invalid
) {
    assertOrderRequestInvalid($invalid);
}

foreach (['customer_id', 'minimarket_id'] as $field) {
    $missing = $base;
    unset($missing[$field]);
    assertOrderRequestInvalid($missing);
}

foreach (['product_id', 'inventory_id', 'quantity'] as $field) {
    $missing = $base;
    unset($missing['items'][0][$field]);
    assertOrderRequestInvalid($missing);
}

echo "PASS order-request-test\n";
