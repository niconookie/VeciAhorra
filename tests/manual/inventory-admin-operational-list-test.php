<?php

declare(strict_types=1);

use VeciAhorra\Admin\Menu;
use VeciAhorra\Modules\Inventory\Admin\InventoryPage;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function inventoryOperationalAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$admins = get_users([
    'role' => 'administrator',
    'number' => 1,
    'fields' => 'ids',
]);
inventoryOperationalAssert($admins !== [], 'Se requiere administrador.');
wp_set_current_user((int) $admins[0]);

(new Menu())->buildMenu();
$page = new InventoryPage();
$page->registerMenu();
$property = (new ReflectionClass($page))->getProperty('pageHook');
$hook = $property->getValue($page);
inventoryOperationalAssert(is_string($hook), 'InventoryPage no se registro.');

$page->enqueueAssets('tools_page_unrelated');
inventoryOperationalAssert(
    ! wp_style_is('veciahorra-inventory-admin', 'enqueued'),
    'Inventory cargo CSS fuera de su pantalla.'
);

$page->enqueueAssets($hook);
inventoryOperationalAssert(
    wp_style_is('veciahorra-inventory-admin', 'enqueued'),
    'Inventory no cargo su CSS.'
);
$modules = wp_script_modules();
$registered = (new ReflectionClass($modules))
    ->getProperty('registered')
    ->getValue($modules);
inventoryOperationalAssert(
    isset($registered['veciahorra-inventory-admin']),
    'Inventory no cargo su modulo JavaScript.'
);

ob_start();
$page->render();
$html = (string) ob_get_clean();
inventoryOperationalAssert(
    preg_match(
        '/<script[^>]+id="veciahorra-inventory-config"[^>]*>(.*?)<\/script>/s',
        $html,
        $matches
    ) === 1,
    'Falta configuracion JSON.'
);
$config = json_decode($matches[1], true);
inventoryOperationalAssert(
    is_array($config)
    && str_ends_with(
        (string) ($config['restUrl'] ?? ''),
        '/wp-json/veciahorra/v1'
    )
    && is_string($config['nonce'] ?? null)
    && ($config['nonce'] ?? '') !== ''
    && str_contains(
        (string) ($config['adminUrl'] ?? ''),
        'page=veciahorra-inventory'
    )
    && str_contains(
        (string) ($config['storeAdminUrl'] ?? ''),
        'page=veciahorra-stores'
    ),
    'Configuracion JavaScript incompleta o insegura.'
);
inventoryOperationalAssert(
    ! str_contains($matches[1], 'email')
    && ! str_contains($matches[1], 'return_url'),
    'La configuracion expuso datos o retornos arbitrarios.'
);

$root = dirname(__DIR__, 2);
$navigation = file_get_contents(
    $root . '/assets/admin/js/modules/inventory/list-navigation.js'
);
$view = file_get_contents(
    $root . '/assets/admin/js/modules/inventory/view.js'
);
inventoryOperationalAssert(
    is_string($navigation)
    && str_contains($navigation, "['view', 'edit']")
    && str_contains($navigation, "'return_search'")
    && ! str_contains($navigation, 'return_url')
    && str_contains($navigation, 'safeAdministrativeRoute'),
    'Builders de navegacion no cumplen el allowlist.'
);
inventoryOperationalAssert(
    is_string($view)
    && str_contains($view, "actions.actionUrl('view'")
    && str_contains($view, "actions.actionUrl(")
    && str_contains($view, "'edit'")
    && ! str_contains($view, 'Eliminar')
    && ! str_contains($view, 'Delete'),
    'Acciones de fila no son las certificadas.'
);

echo "PASS inventory-admin-operational-list-test\n";
