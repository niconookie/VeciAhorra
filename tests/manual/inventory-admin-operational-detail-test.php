<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

function inventoryDetailAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);
$navigation = file_get_contents(
    $root . '/assets/admin/js/modules/inventory/list-navigation.js'
);
$api = file_get_contents($root . '/assets/admin/js/modules/inventory/api.js');
$store = file_get_contents($root . '/assets/admin/js/modules/inventory/store.js');
$view = file_get_contents($root . '/assets/admin/js/modules/inventory/detail-view.js');
$app = file_get_contents($root . '/assets/admin/js/modules/inventory/app.js');
$css = file_get_contents($root . '/assets/admin/css/inventory.css');

foreach ([$navigation, $api, $store, $view, $app, $css] as $source) {
    inventoryDetailAssert(is_string($source), 'Falta un asset de detalle.');
}
foreach ([
    'readInventoryDetailUrl',
    "url.searchParams.get('action') !== 'view'",
    'safeReturnQuery',
    'buildInventoryActionUrl',
] as $fragment) {
    inventoryDetailAssert(
        str_contains($navigation, $fragment),
        "Parser de detalle incompleto: {$fragment}"
    );
}
foreach ([
    '/inventory/${String(id)}/admin',
    'getInventoryAdminDetail',
    'isInventoryAdminDetailResponse',
    "'X-WP-Nonce'",
] as $fragment) {
    inventoryDetailAssert(
        str_contains($api, $fragment),
        "Contrato REST de detalle incompleto: {$fragment}"
    );
}
foreach ([
    'latestDetailRequest',
    'detailController?.abort()',
    "typeof AbortController === 'function'",
    "error?.name === 'AbortError'",
    'cloneValue',
] as $fragment) {
    inventoryDetailAssert(
        str_contains($store, $fragment),
        "Lifecycle de detalle incompleto: {$fragment}"
    );
}
foreach ([
    'Stock registrado',
    'Unidades en reservas activas',
    'last_write_wins',
    'Bloqueos adicionales',
    'Diagnóstico dimensional',
    'safeAdministrativeRoute',
] as $fragment) {
    inventoryDetailAssert(
        str_contains($view, $fragment),
        "Vista de detalle incompleta: {$fragment}"
    );
}
inventoryDetailAssert(
    ! str_contains($view, 'Delete')
    && ! str_contains($view, 'innerHTML'),
    'La vista expone Delete o HTML inseguro.'
);
inventoryDetailAssert(
    str_contains($app, "raw.searchParams.get('action') === 'view'")
    && str_contains($app, 'openDetailEdit')
    && str_contains($app, 'saveAndReturn'),
    'La integración app/detalle/edición está incompleta.'
);
inventoryDetailAssert(
    str_contains($css, '.veciahorra-inventory-admin__detail')
    && ! str_contains($css, '.veciahorra-inventory-admin__detail { overflow: hidden'),
    'CSS de detalle ausente o recorta contenido.'
);

echo "PASS inventory-admin-operational-detail-test\n";
