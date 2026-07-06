<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertInventoryForm(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertInventoryFormSame(mixed $expected, mixed $actual): void
{
    assertInventoryForm(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

function inventoryFormSource(string $path): string
{
    $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    assertInventoryForm(is_string($source), "No fue posible leer {$path}.");

    return $source;
}

function inventoryFormRequest(
    string $method,
    string $route,
    ?array $payload = null
): WP_REST_Response {
    $request = new WP_REST_Request($method, $route);

    if ($payload !== null) {
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode($payload));
    }

    return rest_do_request($request);
}

$administratorIds = get_users([
    'role' => 'administrator',
    'number' => 1,
    'fields' => 'ids',
]);
assertInventoryForm($administratorIds !== [], 'Se requiere un administrador.');
wp_set_current_user((int) $administratorIds[0]);

$api = inventoryFormSource('assets/admin/js/modules/inventory/api.js');
$store = inventoryFormSource('assets/admin/js/modules/inventory/store.js');
$view = inventoryFormSource('assets/admin/js/modules/inventory/view.js');
$app = inventoryFormSource('assets/admin/js/modules/inventory/app.js');
$shell = inventoryFormSource('app/Modules/Inventory/Views/index.php');

foreach (
    [
        'getInventoryItem' => $api,
        'createInventory' => $api,
        'updateInventory' => $api,
        'openCreateForm' => $store,
        'openEditForm' => $store,
        'setFormField' => $store,
        'returnToList' => $store,
        'createInventoryForm' => $view,
        'onSave' => $app,
        'onCancel' => $app,
        'Nuevo inventario' => $shell,
    ] as $fragment => $source
) {
    assertInventoryForm(
        str_contains($source, $fragment),
        "Falta el flujo {$fragment}."
    );
}

foreach (
    ['productId', 'minimarketId', 'price', 'stock', 'status']
    as $field
) {
    assertInventoryForm(
        str_contains($store, $field) && str_contains($view, $field),
        "Falta el campo {$field}."
    );
}

assertInventoryForm(
    str_contains($store, 'if (state.currentView !== VIEW_FORM || state.form.isSaving)')
        && str_contains($store, 'finally')
        && str_contains($store, 'isSaving: false'),
    'El guardado no protege correctamente su ciclo de vida.'
);
assertInventoryForm(
    str_contains($store, "errors.productId = 'Ingrese un Product ID positivo.'")
        && str_contains($store, "errors.minimarketId = 'Ingrese un Minimarket ID positivo.'"),
    'Faltan errores frontend para IDs invalidos.'
);
assertInventoryForm(
    substr_count($api, 'fetch(') === 1
        && ! str_contains($store, 'fetch(')
        && ! str_contains($view, 'fetch(')
        && ! str_contains($app, 'fetch('),
    'Las llamadas REST no estan centralizadas en api.js.'
);

global $wpdb;
$started = $wpdb->query('START TRANSACTION');
assertInventoryForm($started !== false, 'No fue posible iniciar la transaccion.');

try {
    $productId = random_int(7000000, 7999999);
    $minimarketId = random_int(8000000, 8999999);
    $created = inventoryFormRequest('POST', '/veciahorra/v1/inventory', [
        'product_id' => $productId,
        'minimarket_id' => $minimarketId,
        'price' => 1000.5,
        'stock' => 4,
        'status' => 'active',
    ]);
    assertInventoryFormSame(201, $created->get_status());
    $id = (int) ($created->get_data()['data']['id'] ?? 0);
    assertInventoryForm($id > 0, 'Create no retorno ID.');

    $updated = inventoryFormRequest(
        'PATCH',
        '/veciahorra/v1/inventory/' . $id,
        ['price' => 1200.75, 'stock' => 6, 'status' => 'inactive']
    );
    assertInventoryFormSame(200, $updated->get_status());

    $detail = inventoryFormRequest(
        'GET',
        '/veciahorra/v1/inventory/' . $id
    )->get_data()['data'];
    assertInventoryFormSame('1200.75', $detail['price']);
    assertInventoryFormSame(6, (int) $detail['stock']);
    assertInventoryFormSame('inactive', $detail['status']);

    foreach (
        [
            ['product_id' => 0, 'minimarket_id' => 1, 'price' => 1],
            ['product_id' => 1, 'minimarket_id' => 0, 'price' => 1],
        ] as $invalid
    ) {
        $response = inventoryFormRequest(
            'POST',
            '/veciahorra/v1/inventory',
            $invalid
        );
        assertInventoryFormSame(422, $response->get_status());
        assertInventoryFormSame(
            'validation_error',
            $response->get_data()['error']['code'] ?? null
        );
    }

    echo "PASS inventory-admin-form-test\n";
} finally {
    $wpdb->query('ROLLBACK');
}
