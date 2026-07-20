<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertInventoryStoreSelector(bool $condition, string $message): void
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
$selector = file_get_contents($root . '/assets/admin/js/modules/inventory/store-selector.js');

assertInventoryStoreSelector(
    str_contains($api, '/stores?')
        && str_contains($api, 'search: normalizedTerm')
        && str_contains($api, "order_by: 'business_name'")
        && str_contains($api, "direction: 'ASC'")
        && str_contains($api, 'perPage = 10')
        && str_contains($api, "'X-WP-Nonce': nonce")
        && str_contains($api, "credentials: 'same-origin'"),
    'El cliente no consume el transporte administrativo Store real.'
);
assertInventoryStoreSelector(
    str_contains($selector, 'DEBOUNCE_MS = 300')
        && str_contains($selector, 'MINIMUM_TERM_LENGTH = 2')
        && str_contains($selector, 'AbortController')
        && str_contains($selector, 'requestSequence')
        && str_contains($selector, 'sequence === requestSequence'),
    'Debounce o proteccion de concurrencia incompletos.'
);
foreach ([
    'combobox', 'listbox', 'option', 'aria-autocomplete', 'aria-expanded',
    'aria-controls', 'aria-activedescendant', 'aria-selected', 'aria-busy',
    'ArrowDown', 'ArrowUp', 'Enter', 'Escape',
] as $fragment) {
    assertInventoryStoreSelector(
        str_contains($selector, $fragment),
        "Falta comportamiento accesible {$fragment}."
    );
}
assertInventoryStoreSelector(
    str_contains($selector, 'textContent')
        && str_contains($selector, 'createElement')
        && str_contains($selector, 'setAttribute')
        && str_contains($selector, 'append')
        && ! str_contains($selector, 'innerHTML')
        && ! str_contains($selector, 'outerHTML')
        && ! str_contains($selector, 'insertAdjacentHTML'),
    'El componente no certifica DOM seguro.'
);
assertInventoryStoreSelector(
    ! str_contains($app, 'createStoreSelector')
        && ! str_contains($view, 'createStoreSelector')
        && str_contains($view, "createFormInput('minimarketId'")
        && str_contains($store, 'minimarket_id: minimarketId'),
    'El selector fue integrado prematuramente o cambio el contrato Inventory.'
);

$routes = rest_get_server()->get_routes();
assertInventoryStoreSelector(
    isset($routes['/veciahorra/v1/stores']),
    'No existe GET /veciahorra/v1/stores.'
);
$permissionCallback = null;
foreach ($routes['/veciahorra/v1/stores'] as $definition) {
    if (
        is_array($definition)
        && isset($definition['methods'], $definition['permission_callback'])
        && ($definition['methods']['GET'] ?? false) === true
    ) {
        $permissionCallback = $definition['permission_callback'];
        break;
    }
}
assertInventoryStoreSelector(
    is_callable($permissionCallback),
    'La ruta Store no tiene GET y permission_callback administrativo.'
);

function inventoryStoreSelectorBrowser(): string
{
    foreach ([
        (string) getenv('ProgramFiles') . '/Google/Chrome/Application/chrome.exe',
        (string) getenv('ProgramFiles(x86)') . '/Microsoft/Edge/Application/msedge.exe',
    ] as $candidate) {
        if (is_file($candidate)) return $candidate;
    }
    throw new RuntimeException('La prueba requiere Chrome o Edge.');
}

function inventoryStoreSelectorFileUrl(string $path): string
{
    $path = str_replace('\\', '/', realpath($path) ?: $path);
    return 'file:///' . ltrim(
        implode('/', array_map('rawurlencode', explode('/', $path))),
        '/'
    );
}

function removeInventoryStoreSelectorProfile(string $directory): void
{
    if (! is_dir($directory)) return;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir()
            ? @rmdir($file->getPathname())
            : @unlink($file->getPathname());
    }
    @rmdir($directory);
}

$profile = sys_get_temp_dir()
    . '/veciahorra-inventory-store-selector-'
    . getmypid();
$command = [
    inventoryStoreSelectorBrowser(),
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--no-default-browser-check',
    '--allow-file-access-from-files',
    '--user-data-dir=' . $profile,
    '--virtual-time-budget=15000',
    '--dump-dom',
    inventoryStoreSelectorFileUrl(
        __DIR__ . '/inventory-store-selector-test.html'
    ),
];
$pipes = [];
$process = proc_open(
    $command,
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    __DIR__
);
assertInventoryStoreSelector(
    is_resource($process),
    'No se inicio Chrome headless.'
);
$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);
removeInventoryStoreSelectorProfile($profile);
assertInventoryStoreSelector(
    $exit === 0,
    "Chrome fallo ({$exit}): {$errors}"
);
assertInventoryStoreSelector(
    preg_match(
        '/<pre id="test-results" data-status="([^"]+)">([^<]*)<\/pre>/',
        $output,
        $matches
    ) === 1,
    'El arnes no publico resultado.'
);
$status = html_entity_decode(
    $matches[1],
    ENT_QUOTES | ENT_HTML5,
    'UTF-8'
);
$payload = json_decode(
    html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    true
);
assertInventoryStoreSelector(
    $status === 'pass' && is_array($payload),
    'Fallo navegador: status=' . $status
        . ' payload=' . wp_json_encode($payload)
        . ' raw=' . substr($matches[2], 0, 500)
);
assertInventoryStoreSelector(
    count(array_filter($payload['scenarios'] ?? [])) === 15,
    'No pasaron los 15 grupos de escenarios de navegador.'
);

echo "PASS inventory-store-selector-test (browser=15 scenario groups)\n";
