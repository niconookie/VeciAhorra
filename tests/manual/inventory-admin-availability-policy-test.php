<?php

declare(strict_types=1);

use VeciAhorra\Modules\Inventory\Domain\OfferAvailabilityPolicy;
use VeciAhorra\Modules\Inventory\Requests\InventoryAdminListRequest;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function inventoryPolicyAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function inventoryPolicySnapshot(array $overrides = []): array
{
    return [
        'inventory_exists' => true,
        'product_id' => 10,
        'minimarket_id' => 20,
        'resolved_product_id' => 10,
        'resolved_store_id' => 20,
        'product_exists' => true,
        'store_exists' => true,
        'inventory_status' => 'active',
        'product_status' => 'active',
        'store_status' => 'active',
        'store_onboarding_status' => 'complete',
        'store_approved_at' => '2026-01-01 12:00:00',
        'price' => '1000.00',
        'stock' => 5,
        ...$overrides,
    ];
}

$policy = new OfferAvailabilityPolicy();
$public = $policy->evaluate(inventoryPolicySnapshot());
inventoryPolicyAssert(
    $public['is_publicly_available'] === true
    && $public['primary_cause']['code'] === 'publicly_available'
    && $public['blocking_codes'] === []
    && $public['warning_codes'] === [],
    'La oferta publica no fue reconocida.'
);

$cases = [
    'inventory_missing' => ['inventory_exists' => false],
    'product_reference_invalid' => ['product_id' => 0],
    'store_reference_invalid' => ['minimarket_id' => '01'],
    'product_missing' => [
        'product_exists' => false,
        'resolved_product_id' => null,
    ],
    'store_missing' => [
        'store_exists' => false,
        'resolved_store_id' => null,
    ],
    'reference_mismatch' => ['resolved_product_id' => 11],
    'inventory_status_unknown' => ['inventory_status' => 'unexpected'],
    'inventory_inactive' => ['inventory_status' => 'inactive'],
    'product_status_unknown' => ['product_status' => 'unexpected'],
    'product_not_public' => ['product_status' => 'draft'],
    'store_status_unknown' => ['store_status' => 'unexpected'],
    'store_not_active' => ['store_status' => 'inactive'],
    'invalid_public_price' => ['price' => '0.00'],
    'out_of_stock' => ['stock' => 0],
];

foreach ($cases as $expected => $overrides) {
    $result = $policy->evaluate(inventoryPolicySnapshot($overrides));
    inventoryPolicyAssert(
        $result['is_publicly_available'] === false
        && $result['primary_cause']['code'] === $expected,
        "Causa incorrecta para {$expected}."
    );
}

$warning = $policy->evaluate(inventoryPolicySnapshot([
    'store_approved_at' => null,
]));
inventoryPolicyAssert(
    $warning['is_publicly_available'] === true
    && array_column($warning['warnings'], 'code')
        === ['store_lifecycle_inconsistent']
    && $warning['warning_codes'] === ['store_lifecycle_inconsistent'],
    'El lifecycle Store inconsistente bloqueo o no genero warning.'
);

$precedence = $policy->evaluate(inventoryPolicySnapshot([
    'product_id' => 0,
    'inventory_status' => 'inactive',
    'stock' => 0,
]));
inventoryPolicyAssert(
    $precedence['primary_cause']['code'] === 'product_reference_invalid'
    && array_column($precedence['blocking_causes'], 'code') === [
        'product_reference_invalid',
        'inventory_inactive',
        'out_of_stock',
    ]
    && $precedence['blocking_codes'] === [
        'product_reference_invalid',
        'inventory_inactive',
        'out_of_stock',
    ],
    'La precedencia con multiples defectos no es estable.'
);

foreach ([
    ['inventory_status' => ' active '],
    ['product_status' => ['active']],
    ['store_status' => true],
    ['price' => ' 1000.00'],
    ['price' => '1e3'],
    ['price' => INF],
    ['stock' => '1.0'],
] as $corrupt) {
    $result = $policy->evaluate(inventoryPolicySnapshot($corrupt));
    inventoryPolicyAssert(
        $result['is_publicly_available'] === false,
        'La politica acepto una coercion peligrosa.'
    );
}

foreach ([
    ['store_onboarding_status' => 'unknown'],
    ['store_approved_at' => ''],
    ['store_approved_at' => 'not-a-date'],
] as $inconsistentStore) {
    $result = $policy->evaluate(
        inventoryPolicySnapshot($inconsistentStore)
    );
    inventoryPolicyAssert(
        $result['is_publicly_available'] === true
        && array_column($result['warnings'], 'code') === [
            'store_lifecycle_inconsistent',
        ],
        'El lifecycle Store inconsistente no produjo un warning estable.'
    );
}

$operationalInput = inventoryPolicySnapshot([
    'cart' => ['total' => 9],
    'reservations' => ['active' => 4],
    'order_items' => ['total' => 7],
]);
$operational = $policy->evaluate($operationalInput);
inventoryPolicyAssert(
    $operational['is_publicly_available'] === true
    && array_column($operational['warnings'], 'code') === [],
    'La politica mezclo referencias operacionales.'
);
inventoryPolicyAssert(
    isset($operational['dimensions']['references'])
    && count($operational['dimensions']) === 6,
    'Falta diagnostico por dimensiones.'
);

$validRequest = (new InventoryAdminListRequest([
    'search' => 'product:10',
    'status' => 'active',
    'availability' => 'not_public',
    'cause' => 'out_of_stock',
    'reference' => 'active_reservation',
    'page' => '2',
    'per_page' => '50',
    'order_by' => 'price',
    'direction' => 'asc',
]))->validated();
inventoryPolicyAssert(
    $validRequest['page'] === 2
    && $validRequest['per_page'] === 50
    && $validRequest['direction'] === 'ASC',
    'El request administrativo no normalizo la consulta.'
);

foreach ([
    ['nonce' => 'secret'],
    ['redirect' => 'https://evil.test/'],
    ['action' => 'delete'],
    ['inventory_id' => '1'],
    ['search' => ['product:10']],
    ['search' => "\xC3\x28"],
    ['search' => 'inventory:999999999999999999999999'],
    ['product_id' => ['10']],
    ['product_id' => '1.0'],
    ['product_id' => '1e2'],
    ['product_id' => '-1'],
    ['product_id' => '0'],
    ['page' => '1.0'],
    ['page' => '1e2'],
    ['page' => '-1'],
    ['page' => '0'],
    ['page' => '999999999999999999999999'],
    ['per_page' => '21'],
    ['status' => ['active']],
    ['order_by' => 'DROP TABLE inventory'],
    ['direction' => 'SIDEWAYS'],
    ['availability' => 'public', 'cause' => 'out_of_stock'],
] as $invalid) {
    try {
        (new InventoryAdminListRequest($invalid))->validated();
        throw new RuntimeException(
            'InventoryAdminListRequest acepto entrada invalida.'
        );
    } catch (InvalidArgumentException) {
        // Esperado.
    }
}

echo "PASS inventory-admin-availability-policy-test\n";
