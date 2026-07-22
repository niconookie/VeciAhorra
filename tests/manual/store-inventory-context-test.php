<?php
declare(strict_types=1);

use VeciAhorra\Modules\Inventory\Requests\InventoryListRequest;

if (!function_exists('wp_unslash')) {
    function wp_unslash(string $value): string { return stripslashes($value); }
}

$root = dirname(__DIR__, 2);
$paths = [
    'controller' => 'app/Modules/Stores/Controllers/StoresController.php',
    'detail' => 'app/Modules/Stores/Views/detail.php',
    'detailApp' => 'assets/admin/js/modules/stores/detail-app.js',
    'context' => 'assets/admin/js/modules/inventory/context.js',
    'app' => 'assets/admin/js/modules/inventory/app.js',
    'store' => 'assets/admin/js/modules/inventory/store.js',
    'view' => 'assets/admin/js/modules/inventory/view.js',
    'api' => 'assets/admin/js/modules/inventory/api.js',
    'detailView' => 'assets/admin/js/modules/stores/detail-view.js',
];
$sources = [];
foreach ($paths as $name => $relative) {
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) throw new RuntimeException("No se pudo leer {$name}.");
    $sources[$name] = $source;
}
$checks = [
    ['controller', "'minimarket_id' => \$id", 'Store no genera el filtro Inventory.'],
    ['controller', "'return_store_id' => \$id", 'Falta retorno canonico por ID.'],
    ['detail', 'Ofertas del minimarket', 'Falta la seccion contextual.'],
    ['detailApp', 'isInventoryContextUrl', 'Store no revalida las URLs en cliente.'],
    ['context', 'buildStoreContextUrl', 'Inventory no construye contexto Store seguro.'],
    ['context', "key.startsWith('minimarket_id[')", 'No se rechaza sintaxis array.'],
    ['app', 'api.getStore(context.storeId)', 'Inventory no resuelve el Store REST.'],
    ['store', 'form.storeLocked = true', 'El Store contextual no queda bloqueado.'],
    ['view', 'creating && !form.storeLocked', 'El selector Store no se oculta en contexto.'],
    ['view', 'actions.storeDetailUrl(entity.id)', 'Falta regreso canonico al Store.'],
    ['app', "url.searchParams.set('action', 'view')", 'El retorno no usa la accion Store canonica.'],
    ['app', "url.searchParams.set('id', String(id))", 'El retorno no usa el ID Store canonico.'],
    ['app', 'returnToList(store, config.adminUrl, config.storeAdminUrl)', 'Cancelar no recibe el retorno Store certificado.'],
    ['store', 'state.form.storeLocked', 'Las mutaciones frontend no respetan el bloqueo Store.'],
    ['view', 'createProductSelector(actions)', 'El selector Product fue retirado.'],
    ['detailView', "item.lifecycle_state !== 'invalid'", 'Invalid no elimina enlaces contextuales.'],
    ['detailView', 'hideInventory();', 'Los estados operativos no retiran la navegacion contextual.'],
    ['detailApp', 'view.abandon();', 'Abandoned conserva navegacion contextual operativa.'],
];
foreach ($checks as [$file, $needle, $message]) {
    if (!str_contains($sources[$file], $needle)) throw new RuntimeException($message);
}
if (str_contains($sources['detailApp'], 'getInventory(')
    || str_contains($sources['detailView'], 'fetch(')
    || str_contains($sources['controller'], 'InventoryService')) {
    throw new RuntimeException('El detalle Store no debe consultar Inventory.');
}

require_once $root . '/app/Modules/Inventory/Requests/InventoryListRequest.php';
$valid = (new InventoryListRequest(['product_id' => '7', 'minimarket_id' => '11']))->validated();
if ($valid['product_id'] !== 7 || $valid['minimarket_id'] !== 11) {
    throw new RuntimeException('Los filtros Product y Store no coexisten en backend.');
}
$invalidIds = ['', '0', '-1', '+1', '01', '1.0', '1e2', "1\r\n", '١', ((string) PHP_INT_MAX) . '0', ['1'], ['1', '2']];
foreach ($invalidIds as $invalidId) {
    $request = new InventoryListRequest(['minimarket_id' => $invalidId]);
    try {
        $request->validated();
    } catch (InvalidArgumentException) {
        continue;
    }
    throw new RuntimeException('Inventory acepto un minimarket_id no canonico: ' . var_export($invalidId, true));
}
echo "PASS store-inventory-context-test\n";
