<?php

declare(strict_types=1);

use VeciAhorra\Admin\Menu;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function storeListUiAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
storeListUiAssert($admins !== [], 'Se requiere administrador.');
wp_set_current_user((int) $admins[0]);
$menuInstance = new Menu();
$menuInstance->buildMenu();
$property = new ReflectionProperty(Menu::class, 'storesPageHook');
$hook = $property->getValue($menuInstance);
storeListUiAssert(is_string($hook) && $hook !== '', 'No se registro hook Stores.');
$menuInstance->enqueueStoreAssets('veciahorra_page_veciahorra-inventory');
storeListUiAssert(! wp_style_is('veciahorra-stores-admin', 'enqueued'), 'CSS Stores se cargo en otra pantalla.');
$menuInstance->enqueueStoreAssets($hook);
storeListUiAssert(wp_style_is('veciahorra-stores-admin', 'enqueued'), 'CSS Stores no encolado.');
$modules = wp_script_modules();
$registered = (new ReflectionProperty($modules, 'registered'))->getValue($modules);
storeListUiAssert(isset($registered['veciahorra-stores-admin']), 'Modulo JS Stores no registrado.');

$root = dirname(__DIR__, 2);
$view = file_get_contents($root . '/app/Modules/Stores/Views/index.php');
$script = file_get_contents($root . '/assets/admin/js/modules/stores/app.js');
$css = file_get_contents($root . '/assets/admin/css/stores.css');
foreach (['va-stores-search', 'va-stores-lifecycle', 'va-stores-status', 'va-stores-sort', 'aria-busy', 'role="region"'] as $fragment) {
    storeListUiAssert(str_contains($view, $fragment), 'Vista sin ' . $fragment . '.');
}
foreach (['context', 'admin_list', 'lifecycle_state', 'replaceChildren', 'textContent', 'pushState', 'popstate', 'Ver detalle', 'Editar', "root.dataset.initialized", 'AbortController', 'latestRequest', 'requestId !== latestRequest'] as $fragment) {
    storeListUiAssert(str_contains($script, $fragment), 'JavaScript sin ' . $fragment . '.');
}
foreach (["searchParams.set('action', 'view')", "searchParams.set('id', String(id))", 'return_search', 'return_lifecycle_state', 'return_status', 'return_sort', 'return_paged'] as $fragment) {
    storeListUiAssert(str_contains($script, $fragment), 'Enlace de detalle sin ' . $fragment . '.');
}
storeListUiAssert(substr_count($script, 'fetch(') === 1, 'Debe existir un solo punto fetch.');
storeListUiAssert(! str_contains($script, 'innerHTML'), 'JavaScript usa innerHTML.');
storeListUiAssert(! str_contains($script, 'insertAdjacentHTML') && ! str_contains($script, 'eval('), 'JavaScript contiene un sumidero inseguro.');
foreach (['submit_for_review', 'return_to_draft', '/transitions', 'DELETE', 'Aprobar', 'Eliminar'] as $forbidden) {
    storeListUiAssert(! str_contains($script, $forbidden), 'Listado contiene accion prohibida ' . $forbidden . '.');
}
storeListUiAssert(str_contains($css, '@media (max-width: 960px)') && str_contains($css, '@media (max-width: 600px)') && str_contains($css, 'overflow-x: auto'), 'CSS no cubre tablet/movil.');
wp_set_current_user(0);
echo "PASS store-admin-operational-list-ui-test\n";
