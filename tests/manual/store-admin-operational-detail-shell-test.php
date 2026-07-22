<?php

declare(strict_types=1);

use VeciAhorra\Admin\Menu;
use VeciAhorra\Modules\Stores\Controllers\StoresController;
use VeciAhorra\Modules\Stores\Requests\StoreAdminPageRequest;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function detailShellAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function detailShellSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\n" . var_export([$expected, $actual], true));
    }
}

function detailShellRender(array $query): string
{
    $previousGet = $_GET;
    $_GET = $query;
    ob_start();
    try {
        (new StoresController())->index();
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
        $_GET = $previousGet;
    }
}

$root = dirname(__DIR__, 2);
$validIds = ['1', '25', '999999995'];
foreach ($validIds as $value) {
    $request = new StoreAdminPageRequest(['action' => 'view', 'id' => $value]);
    detailShellSame(StoreAdminPageRequest::SCREEN_DETAIL, $request->screen(), "ID válido rechazado: {$value}");
    detailShellSame((int) $value, $request->storeId(), 'ID no conservado.');
}

detailShellSame(StoreAdminPageRequest::SCREEN_LIST, (new StoreAdminPageRequest([]))->screen(), 'Listado sin action no resuelto.');
foreach (['', 'edit', 'detail', 'show', 'View', 'VIEW', ' view', 'view ', 'preview'] as $action) {
    detailShellSame(StoreAdminPageRequest::SCREEN_UNKNOWN_ACTION, (new StoreAdminPageRequest(['action' => $action]))->screen(), "Acción aceptada: {$action}");
}
detailShellSame(StoreAdminPageRequest::SCREEN_UNKNOWN_ACTION, (new StoreAdminPageRequest(['action' => ['view']]))->screen(), 'Action array aceptada.');
detailShellSame(StoreAdminPageRequest::SCREEN_UNKNOWN_ACTION, (new StoreAdminPageRequest(['action' => (object) ['value' => 'view']]))->screen(), 'Action objeto aceptada.');
detailShellSame(StoreAdminPageRequest::SCREEN_UNKNOWN_ACTION, (new StoreAdminPageRequest(['action' => 'view', 'id' => '1'], ['action']))->screen(), 'Action duplicada aceptada.');
detailShellSame(StoreAdminPageRequest::SCREEN_INVALID_DETAIL, (new StoreAdminPageRequest(['action' => 'view', 'id' => '1'], ['id']))->screen(), 'ID duplicado aceptado.');
foreach ([null, '', '0', '01', '+1', '-1', '1.0', '1e2', ' 1', '1 ', 'abc', ['1'], str_repeat('9', 100)] as $id) {
    $query = ['action' => 'view'];
    if ($id !== null) $query['id'] = $id;
    detailShellSame(StoreAdminPageRequest::SCREEN_INVALID_DETAIL, (new StoreAdminPageRequest($query))->screen(), 'ID inválido aceptado: ' . var_export($id, true));
}
$maximumId = (string) PHP_INT_MAX;
detailShellSame(PHP_INT_MAX, (new StoreAdminPageRequest(['action' => 'view', 'id' => $maximumId]))->storeId(), 'PHP_INT_MAX fue rechazado.');
foreach (['1' . $maximumId, str_repeat('9', strlen($maximumId) + 100), "1\n", '1abc', (object) ['value' => '1']] as $overflowId) {
    detailShellSame(StoreAdminPageRequest::SCREEN_INVALID_DETAIL, (new StoreAdminPageRequest(['action' => 'view', 'id' => $overflowId]))->screen(), 'Borde de ID inválido aceptado.');
}

$returnRequest = new StoreAdminPageRequest([
    'action' => 'view',
    'id' => '25',
    'return_search' => 'Mercado sur',
    'return_lifecycle_state' => 'approved_inactive',
    'return_status' => 'inactive',
    'return_sort' => 'updated',
    'return_paged' => '12',
    'return_url' => 'https://evil.test/',
    'unknown' => 'discarded',
]);
detailShellSame([
    'search' => 'Mercado sur',
    'lifecycle_state' => 'approved_inactive',
    'status' => 'inactive',
    'sort' => 'updated',
    'paged' => 12,
], $returnRequest->returnQuery(), 'Retorno combinado incorrecto.');
$returnUrl = $returnRequest->returnUrl();
$returnParts = wp_parse_url($returnUrl);
parse_str((string) ($returnParts['query'] ?? ''), $returnQuery);
detailShellSame('veciahorra-stores', $returnQuery['page'] ?? null, 'Slug de retorno ausente.');
foreach (['action', 'id', 'return_url', 'unknown'] as $forbidden) {
    detailShellAssert(! array_key_exists($forbidden, $returnQuery), "Retorno preservó {$forbidden}.");
}
detailShellSame(wp_parse_url(admin_url('admin.php'), PHP_URL_HOST), $returnParts['host'] ?? null, 'Retorno salió del host administrativo.');

foreach ([
    ['return_search' => ['array']],
    ['return_search' => str_repeat('x', 101)],
    ['return_lifecycle_state' => 'ACTIVE'],
    ['return_lifecycle_state' => ' active'],
    ['return_status' => 'active '],
    ['return_status' => 'unknown'],
    ['return_sort' => 'business_name_asc'],
    ['return_paged' => '01'],
    ['return_paged' => '1000001'],
    ['return_paged' => ['2']],
] as $invalidReturn) {
    $request = new StoreAdminPageRequest(['action' => 'view', 'id' => '2'] + $invalidReturn);
    detailShellSame([], $request->returnQuery(), 'Retorno inválido no se descartó: ' . var_export($invalidReturn, true));
}
$specialSearches = [
    '',
    "%_&=#'\" Ñandú",
    'https://example.com',
    '//example.com',
    '/wp-admin/admin.php?page=otro',
    '%252F%252Fexample.com',
    "línea\r\nsiguiente",
];
foreach ($specialSearches as $search) {
    $request = new StoreAdminPageRequest(['action' => 'view', 'id' => '2', 'return_search' => $search]);
    detailShellAssert(array_key_exists('search', $request->returnQuery()), 'Búsqueda textual especial descartada.');
    $url = $request->returnUrl();
    detailShellSame(wp_parse_url(admin_url('admin.php'), PHP_URL_HOST), wp_parse_url($url, PHP_URL_HOST), 'Búsqueda alteró host de retorno.');
    detailShellSame(wp_parse_url(admin_url('admin.php'), PHP_URL_PATH), wp_parse_url($url, PHP_URL_PATH), 'Búsqueda alteró path de retorno.');
}
foreach (['draft', 'in_review', 'rejected', 'approved_inactive', 'active', 'invalid'] as $state) {
    detailShellSame(['lifecycle_state' => $state], (new StoreAdminPageRequest(['action' => 'view', 'id' => '2', 'return_lifecycle_state' => $state]))->returnQuery(), 'Lifecycle de retorno rechazado.');
}
foreach (['pending', 'active', 'inactive', 'rejected'] as $status) {
    detailShellSame(['status' => $status], (new StoreAdminPageRequest(['action' => 'view', 'id' => '2', 'return_status' => $status]))->returnQuery(), 'Status de retorno rechazado.');
}
foreach (['name_asc', 'newest', 'oldest', 'updated'] as $sort) {
    detailShellSame(['sort' => $sort], (new StoreAdminPageRequest(['action' => 'view', 'id' => '2', 'return_sort' => $sort]))->returnQuery(), 'Sort real rechazado.');
}

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
detailShellAssert($admins !== [], 'Se requiere administrador.');
wp_set_current_user((int) $admins[0]);
$validHtml = detailShellRender(['page' => 'veciahorra-stores', 'action' => 'view', 'id' => '25']);
detailShellSame(1, substr_count($validHtml, '<h1>'), 'El shell no tiene un h1 único.');
foreach (['data-va-store-detail', 'Volver a minimarkets', '<main', 'aria-live="polite"', 'aria-busy="true"', '<noscript>', 'Resumen administrativo', 'Lifecycle', 'Información comercial', 'Acciones', 'Zona sensible', 'va-store-detail-config'] as $fragment) {
    detailShellAssert(str_contains($validHtml, $fragment), 'Shell sin ' . $fragment . '.');
}
foreach (['business_name', 'legal_name', 'owner_name', 'rut', 'email', 'phone', 'mobile', 'allowed_actions', '/transitions', 'delete_if_unreferenced', '<form'] as $forbidden) {
    detailShellAssert(! str_contains($validHtml, $forbidden), 'Shell expone funcionalidad/dato prohibido: ' . $forbidden);
}
$configStart = strpos($validHtml, '<script type="application/json" id="va-store-detail-config">');
detailShellAssert($configStart !== false, 'Configuración ausente.');
preg_match('~<script type="application/json" id="va-store-detail-config">(.*?)</script>~s', $validHtml, $configMatch);
$config = json_decode(html_entity_decode($configMatch[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
detailShellSame(true, $config['enabled'] ?? null, 'Config enabled incorrecta.');
detailShellSame(25, $config['storeId'] ?? null, 'Config ID incorrecta.');
foreach (['detailUrl', 'nonce', 'updateUrl', 'updateNonce', 'returnUrl'] as $key) detailShellAssert(is_string($config[$key] ?? null) && $config[$key] !== '', "Config sin {$key}.");
detailShellSame(['enabled', 'storeId', 'detailUrl', 'nonce', 'updateUrl', 'updateNonce', 'returnUrl', 'inventoryListUrl', 'inventoryCreateUrl'], array_keys($config), 'Config contiene campos inesperados.');
foreach ([
    'inventoryListUrl' => ['page' => 'veciahorra-inventory', 'minimarket_id' => '25', 'return_store_id' => '25'],
    'inventoryCreateUrl' => ['page' => 'veciahorra-inventory', 'action' => 'create', 'minimarket_id' => '25', 'return_store_id' => '25'],
] as $key => $expectedQuery) {
    $parts = wp_parse_url($config[$key] ?? '');
    parse_str($parts['query'] ?? '', $actualQuery);
    detailShellAssert(str_ends_with($parts['path'] ?? '', '/admin.php'), "{$key} no usa admin.php.");
    detailShellSame($expectedQuery, $actualQuery, "{$key} no respeta el contrato canonico.");
}

$previousGet = $_GET;
$_GET = ['page' => 'veciahorra-stores', 'action' => 'view', 'id' => '25'];
$controllerWithoutRepository = new StoresController();
ob_start();
$controllerWithoutRepository->index();
ob_end_clean();
$_GET = $previousGet;
$serviceProperty = new ReflectionProperty(StoresController::class, 'service');
detailShellSame(null, $serviceProperty->getValue($controllerWithoutRepository), 'El shell construyó StoreService.');

$invalidHtml = detailShellRender(['page' => 'veciahorra-stores', 'action' => 'view', 'id' => '01']);
detailShellAssert(str_contains($invalidHtml, 'Minimarket inválido') && str_contains($invalidHtml, 'Volver a minimarkets'), 'Error de ID no es recuperable.');
detailShellAssert(! str_contains($invalidHtml, 'va-store-detail-config') && ! str_contains($invalidHtml, '<main'), 'ID inválido inicializa la aplicación.');
$unknownHtml = detailShellRender(['page' => 'veciahorra-stores', 'action' => 'edit', 'id' => '1']);
detailShellAssert(str_contains($unknownHtml, 'acción administrativa solicitada no es válida'), 'Acción desconocida no se cerró.');
$listHtml = detailShellRender(['page' => 'veciahorra-stores']);
detailShellAssert(str_contains($listHtml, 'id="va-stores-app"') && ! str_contains($listHtml, 'data-va-store-detail'), 'Listado dejó de renderizar su vista.');

$menuInstance = new Menu();
$menuInstance->buildMenu();
$hookProperty = new ReflectionProperty(Menu::class, 'storesPageHook');
$hook = $hookProperty->getValue($menuInstance);
detailShellAssert(is_string($hook), 'Hook Store no registrado.');
$_GET = ['page' => 'veciahorra-stores', 'action' => 'view', 'id' => '01'];
$menuInstance->enqueueStoreAssets($hook);
$modules = wp_script_modules();
$registered = (new ReflectionProperty($modules, 'registered'))->getValue($modules);
detailShellAssert(! isset($registered['veciahorra-store-detail-admin']), 'ID inválido registró JS detalle.');
$_GET = ['page' => 'veciahorra-store-create'];
$menuInstance->enqueueStoreAssets('veciahorra_page_veciahorra-store-create');
$registered = (new ReflectionProperty($modules, 'registered'))->getValue($modules);
detailShellAssert(! isset($registered['veciahorra-store-detail-admin']), 'Creación registró JS detalle.');
$_GET = ['page' => 'veciahorra-store-edit', 'id' => '25'];
$menuInstance->enqueueStoreAssets('veciahorra_page_veciahorra-store-edit');
$registered = (new ReflectionProperty($modules, 'registered'))->getValue($modules);
detailShellAssert(! isset($registered['veciahorra-store-detail-admin']), 'Edición heredada registró JS detalle.');
$_GET = ['page' => 'veciahorra-stores', 'action' => 'view', 'id' => '25'];
$menuInstance->enqueueStoreAssets($hook);
detailShellAssert(wp_style_is('veciahorra-store-detail-admin', 'enqueued'), 'CSS detalle no encolado.');
$registered = (new ReflectionProperty($modules, 'registered'))->getValue($modules);
detailShellAssert(isset($registered['veciahorra-store-detail-admin']), 'JS detalle no registrado.');
detailShellAssert(! isset($registered['veciahorra-stores-admin']), 'JS listado se registró en detalle.');

$view = file_get_contents($root . '/app/Modules/Stores/Views/detail.php');
$script = file_get_contents($root . '/assets/admin/js/modules/stores/detail-app.js');
$css = file_get_contents($root . '/assets/admin/css/stores-detail.css');
foreach (['textContent', 'Object.assign', 'window.VeciAhorra = window.VeciAhorra || {}', 'vaStoreDetailInitialized'] as $fragment) {
    detailShellAssert(str_contains($script, $fragment), 'JS infraestructura sin ' . $fragment . '.');
}
foreach (['fetch(', 'XMLHttpRequest', '/transitions', 'DELETE', 'POST', 'PUT', 'PATCH', 'console.log', 'localStorage', 'sessionStorage'] as $forbidden) {
    detailShellAssert(! str_contains($script, $forbidden), 'JS contiene operación prohibida: ' . $forbidden);
}
detailShellAssert(! str_contains($view, 'role="button"') && ! str_contains($view, 'tabindex="1"'), 'Vista usa semántica inaccesible.');
detailShellAssert(str_contains($css, '.va-store-detail') && str_contains($css, '@media (max-width: 782px)'), 'CSS sin alcance/responsive.');
foreach (['!important', 'http://', 'https://'] as $forbidden) detailShellAssert(! str_contains($css, $forbidden), 'CSS contiene patrón prohibido.');
detailShellAssert(preg_match('/^\s*height\s*:/m', $css) !== 1, 'CSS usa altura rígida.');

wp_set_current_user(0);
echo "PASS store-admin-operational-detail-shell-test\n";
