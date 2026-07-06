<?php

declare(strict_types=1);

use VeciAhorra\Modules\Inventory\Admin\InventoryPage;
use VeciAhorra\Admin\Menu;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertInventoryAdmin(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function inventoryAdminSource(string $relativePath): string
{
    $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);

    if (! is_string($contents)) {
        throw new RuntimeException("No fue posible leer {$relativePath}.");
    }

    return $contents;
}

$administratorIds = get_users([
    'role' => 'administrator',
    'number' => 1,
    'fields' => 'ids',
]);
assertInventoryAdmin(
    $administratorIds !== [],
    'La prueba requiere un usuario administrador.'
);
wp_set_current_user((int) $administratorIds[0]);

(new Menu())->buildMenu();
$page = new InventoryPage();
$page->registerMenu();
$reflection = new ReflectionClass($page);
$pageHookProperty = $reflection->getProperty('pageHook');
$pageHook = $pageHookProperty->getValue($page);

assertInventoryAdmin(is_string($pageHook), 'No se registro la pantalla Inventory.');

$page->enqueueAssets($pageHook);
assertInventoryAdmin(
    wp_style_is('veciahorra-inventory-admin', 'enqueued'),
    'No se encolo el CSS de Inventory.'
);

$modules = wp_script_modules();
$modulesReflection = new ReflectionClass($modules);
$registeredProperty = $modulesReflection->getProperty('registered');
$registeredModules = $registeredProperty->getValue($modules);
assertInventoryAdmin(
    isset($registeredModules['veciahorra-inventory-admin']),
    'No se registro el modulo JavaScript de Inventory.'
);

$app = inventoryAdminSource('assets/admin/js/modules/inventory/app.js');
$api = inventoryAdminSource('assets/admin/js/modules/inventory/api.js');
$store = inventoryAdminSource('assets/admin/js/modules/inventory/store.js');
$view = inventoryAdminSource('assets/admin/js/modules/inventory/view.js');
$pageSource = inventoryAdminSource(
    'app/Modules/Inventory/Admin/InventoryPage.php'
);
$application = inventoryAdminSource('app/Core/Application.php');

foreach (
    [
        'createInventoryApi' => $api,
        'createInventoryStore' => $store,
        'createInventoryView' => $view,
    ] as $symbol => $source
) {
    assertInventoryAdmin(
        str_contains($source, $symbol),
        "Falta {$symbol}."
    );
}

assertInventoryAdmin(
    str_contains($api, "return `/inventory?")
        && str_contains($api, "method: 'GET'"),
    'El API client no solicita GET /inventory.'
);

foreach (
    ['ID', 'Product ID', 'Minimarket ID', 'Price', 'Stock', 'Status', 'Updated At']
    as $column
) {
    assertInventoryAdmin(
        str_contains($view, "'{$column}'"),
        "Falta la columna {$column}."
    );
}

foreach (
    [
        "['search', search]",
        "['product_id', productId]",
        "['minimarket_id', minimarketId]",
        "['status', status]",
        'per_page: String(perPage)',
        'page: String(page)',
    ] as $queryFragment
) {
    assertInventoryAdmin(
        str_contains($api, $queryFragment),
        "No se envia el filtro {$queryFragment}."
    );
}

assertInventoryAdmin(
    str_contains($store, 'function applyFilters()')
        && str_contains($store, 'function goToPage(page)')
        && str_contains($view, 'actions.onSearch()')
        && str_contains($view, 'actions.onPage('),
    'Busqueda o paginacion no estan conectadas al Store.'
);

foreach (
    [
        'Cargando inventario...',
        'No hay registros de inventario para mostrar.',
        'No fue posible cargar el inventario.',
    ] as $message
) {
    assertInventoryAdmin(
        str_contains($view, $message),
        "Falta el estado visible: {$message}"
    );
}

assertInventoryAdmin(
    substr_count($api, 'fetch(') === 1,
    'El API client debe centralizar una unica llamada fetch.'
);

foreach ([$app, $store, $view] as $source) {
    assertInventoryAdmin(
        ! str_contains($source, 'fetch('),
        'Existe una llamada REST fuera del API client.'
    );
}

assertInventoryAdmin(
    str_contains($pageSource, 'assets/admin/js/modules/inventory/app.js')
        && str_contains($pageSource, 'assets/admin/css/inventory.css'),
    'InventoryPage no registra todos sus assets.'
);
assertInventoryAdmin(
    substr_count($application, 'InventoryPage::class') === 1,
    'Application debe resolver InventoryPage una sola vez.'
);

echo "PASS inventory-admin-list-test\n";
