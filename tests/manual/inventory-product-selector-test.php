<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertInventoryProductSelector(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);
$api = file_get_contents($root . '/assets/admin/js/modules/inventory/api.js');
$app = file_get_contents($root . '/assets/admin/js/modules/inventory/app.js');
$store = file_get_contents($root . '/assets/admin/js/modules/inventory/store.js');
$view = file_get_contents($root . '/assets/admin/js/modules/inventory/view.js');
$selector = file_get_contents($root . '/assets/admin/js/modules/inventory/product-selector.js');
$css = file_get_contents($root . '/assets/admin/css/inventory.css');

assertInventoryProductSelector(
    str_contains($api, '/products/search?')
        && str_contains($api, "term: normalizedTerm")
        && str_contains($api, 'perPage = 10'),
    'Inventory no reutiliza la búsqueda administrativa limitada.'
);
assertInventoryProductSelector(
    str_contains($app, 'api.searchProducts(term)')
        && str_contains($app, 'store.selectProduct(product)')
        && str_contains($app, 'store.clearSelectedProduct()'),
    'La aplicación no integra selector, API y store.'
);
assertInventoryProductSelector(
    str_contains($store, 'product_id: productId')
        && str_contains($store, 'selectedProduct')
        && str_contains($store, 'inventory_product_not_found')
        && str_contains($store, 'reason:'),
    'El store no conserva el contrato o los errores estructurados.'
);
assertInventoryProductSelector(
    str_contains($view, "import { createProductSelector }")
        && str_contains($view, 'form.mode === FORM_CREATE')
        && str_contains($view, 'form.productLocked'),
    'La vista no separa creación ordinaria, contexto y edición.'
);
foreach (['role', 'combobox', 'listbox', 'aria-expanded', 'ArrowDown', 'ArrowUp', 'Escape', 'Enter'] as $fragment) {
    assertInventoryProductSelector(
        str_contains($selector, $fragment),
        "Falta comportamiento accesible {$fragment}."
    );
}
assertInventoryProductSelector(
    str_contains($selector, 'DEBOUNCE_MS = 300')
        && str_contains($selector, 'MINIMUM_TERM_LENGTH = 2')
        && str_contains($selector, 'requestSequence')
        && str_contains($selector, 'sequence !== requestSequence')
        && str_contains($selector, 'compositionstart')
        && str_contains($selector, 'focusout')
        && str_contains($selector, 'const unique = new Map()')
        && str_contains($selector, 'textContent')
        && ! str_contains($selector, 'innerHTML')
        && ! str_contains($selector, 'outerHTML')
        && ! str_contains($selector, 'insertAdjacentHTML'),
    'Debounce, concurrencia o DOM seguro no certificados.'
);
assertInventoryProductSelector(
    str_contains($css, '.veciahorra-inventory-admin__product-selector')
        && ! str_contains($css, '@import'),
    'El selector no tiene CSS acotado.'
);

$routes = rest_get_server()->get_routes();
assertInventoryProductSelector(
    isset($routes['/veciahorra/v1/products/search']),
    'No existe GET /veciahorra/v1/products/search.'
);
assertInventoryProductSelector(
    ! isset($routes['/veciahorra/v1/public/products/search']),
    'La búsqueda fue expuesta mediante una ruta pública.'
);
$routeDefinitions = $routes['/veciahorra/v1/products/search'];
$permissionCallback = null;
foreach ($routeDefinitions as $definition) {
    if (is_array($definition) && isset($definition['permission_callback'])) {
        $permissionCallback = $definition['permission_callback'];
        break;
    }
}
assertInventoryProductSelector(
    is_callable($permissionCallback),
    'La ruta no tiene permission_callback administrativo.'
);

function selectorBrowser(): string
{
    foreach ([
        (string) getenv('ProgramFiles') . '/Google/Chrome/Application/chrome.exe',
        (string) getenv('ProgramFiles(x86)') . '/Microsoft/Edge/Application/msedge.exe',
    ] as $candidate) {
        if (is_file($candidate)) return $candidate;
    }
    throw new RuntimeException('La prueba requiere Chrome o Edge.');
}

function selectorFileUrl(string $path): string
{
    $path = str_replace('\\', '/', realpath($path) ?: $path);
    return 'file:///' . ltrim(implode('/', array_map('rawurlencode', explode('/', $path))), '/');
}

function removeSelectorProfile(string $directory): void
{
    if (! is_dir($directory)) return;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($directory);
}

$profile = sys_get_temp_dir() . '/veciahorra-inventory-selector-' . getmypid();
$command = [
    selectorBrowser(), '--headless=new', '--disable-gpu', '--no-first-run',
    '--no-default-browser-check', '--allow-file-access-from-files',
    '--user-data-dir=' . $profile, '--virtual-time-budget=12000', '--dump-dom',
    selectorFileUrl(__DIR__ . '/inventory-product-selector-test.html'),
];
$pipes = [];
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
assertInventoryProductSelector(is_resource($process), 'No se inició Chrome headless.');
$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$exit = proc_close($process);
removeSelectorProfile($profile);
assertInventoryProductSelector($exit === 0, "Chrome falló ({$exit}): {$errors}");
assertInventoryProductSelector(
    preg_match('/<pre id="test-results" data-status="([^"]+)">([^<]*)<\/pre>/', $output, $matches) === 1,
    'El arnés no publicó resultado.'
);
$status = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
$payload = json_decode(html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
assertInventoryProductSelector(
    $status === 'pass' && is_array($payload),
    'Fallo navegador: ' . wp_json_encode($payload)
);
assertInventoryProductSelector(
    count(array_filter($payload['scenarios'] ?? [])) === 32,
    'No pasaron los 32 escenarios de navegador.'
);

echo "PASS inventory-product-selector-test (browser=32 scenarios)\n";
