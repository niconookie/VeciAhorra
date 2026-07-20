<?php

declare(strict_types=1);

use VeciAhorra\Modules\Inventory\Admin\InventoryPage;
use VeciAhorra\Modules\Products\Admin\ProductsPage;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertProductInventoryContext(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function contextConfig(object $page, string $elementId): array
{
    ob_start();
    $page->render();
    $html = (string) ob_get_clean();
    $pattern = sprintf(
        '/<script[^>]+id="%s"[^>]*>(.*?)<\/script>/s',
        preg_quote($elementId, '/')
    );
    assertProductInventoryContext(
        preg_match($pattern, $html, $matches) === 1,
        'No se encontro la configuracion administrativa.'
    );
    $config = json_decode($matches[1], true);
    assertProductInventoryContext(is_array($config), 'La configuracion no es JSON valido.');

    return $config;
}

$root = dirname(__DIR__, 2);
$productsConfig = contextConfig(
    new ProductsPage(),
    'veciahorra-products-config'
);
$inventoryConfig = contextConfig(
    new InventoryPage(),
    'veciahorra-inventory-config'
);
$expectedUrl = add_query_arg(
    ['page' => InventoryPage::PAGE_SLUG],
    admin_url('admin.php')
);

assertProductInventoryContext(
    ($productsConfig['inventoryUrl'] ?? null) === $expectedUrl,
    'Products no recibe la URL canonica de Inventory.'
);
assertProductInventoryContext(
    ($inventoryConfig['adminUrl'] ?? null) === $expectedUrl,
    'Inventory no recibe su URL administrativa canonica.'
);

$productView = file_get_contents($root . '/assets/admin/products/view.js');
$productApp = file_get_contents($root . '/assets/admin/products/app.js');
$navigation = file_get_contents($root . '/assets/admin/products/navigation.js');
$inventoryApp = file_get_contents($root . '/assets/admin/js/modules/inventory/app.js');
$inventoryStore = file_get_contents($root . '/assets/admin/js/modules/inventory/store.js');
$inventoryView = file_get_contents($root . '/assets/admin/js/modules/inventory/view.js');
$inventoryApi = file_get_contents($root . '/assets/admin/js/modules/inventory/api.js');
$context = file_get_contents($root . '/assets/admin/js/modules/inventory/context.js');

foreach (['Ver ofertas', 'Crear oferta', 'createLink'] as $fragment) {
    assertProductInventoryContext(
        str_contains($productView, $fragment),
        "Falta la accion Product {$fragment}."
    );
}
assertProductInventoryContext(
    str_contains($productApp, 'config.inventoryUrl')
        && str_contains($navigation, "searchParams.set('product_id'")
        && str_contains($navigation, "searchParams.set('action', 'create')"),
    'Product no construye el contexto mediante URL API.'
);
assertProductInventoryContext(
    ! str_contains($productView, 'admin.php?page=')
        && ! str_contains($navigation, 'veciahorra-inventory'),
    'La vista Product contiene una URL o slug administrativo hardcodeado.'
);

foreach (
    [
        "getAll('product_id')",
        "getAll('action')",
        '/^[1-9]\\d*$/',
        'Number.isSafeInteger',
    ] as $fragment
) {
    assertProductInventoryContext(
        str_contains($context, $fragment),
        "Falta validacion contextual estricta: {$fragment}."
    );
}
assertProductInventoryContext(
    str_contains($inventoryApp, 'api.getProduct(context.productId)')
        && str_contains($inventoryApp, 'window.location.assign')
        && str_contains($inventoryApi, '`/products/${String(id)}`'),
    'Inventory no valida el Product mediante su detalle administrativo.'
);
assertProductInventoryContext(
    str_contains($inventoryStore, '{ ...DEFAULT_FILTERS, productId }')
        && str_contains($inventoryStore, 'openCreateForm(normalizedProduct)')
        && str_contains($inventoryStore, 'form.productLocked = true'),
    'Inventory no aplica filtro o precarga contextual.'
);
assertProductInventoryContext(
    str_contains($inventoryView, 'Ofertas de:')
        && str_contains($inventoryView, 'Ver todas las ofertas')
        && str_contains($inventoryView, 'Este producto todavia no tiene ofertas.')
        && str_contains($inventoryView, 'Crear primera oferta'),
    'Faltan estados visibles o recuperacion del contexto.'
);
assertProductInventoryContext(
    str_contains($inventoryView, 'textContent')
        && ! str_contains($inventoryView, 'innerHTML'),
    'El contexto no usa construccion segura de nodos.'
);

function contextBrowser(): string
{
    foreach (
        [
            (string) getenv('ProgramFiles') . '/Google/Chrome/Application/chrome.exe',
            (string) getenv('ProgramFiles(x86)') . '/Microsoft/Edge/Application/msedge.exe',
        ] as $candidate
    ) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('La prueba requiere Chrome o Edge instalado.');
}

function contextFileUrl(string $path): string
{
    $path = str_replace('\\', '/', realpath($path) ?: $path);

    return 'file:///' . ltrim(implode('/', array_map(
        'rawurlencode',
        explode('/', $path)
    )), '/');
}

function removeContextProfile(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }

    @rmdir($directory);
}

$profile = sys_get_temp_dir() . '/veciahorra-product-inventory-' . getmypid();
$command = [
    contextBrowser(),
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--no-default-browser-check',
    '--allow-file-access-from-files',
    '--user-data-dir=' . $profile,
    '--virtual-time-budget=10000',
    '--dump-dom',
    contextFileUrl(__DIR__ . '/product-inventory-context-test.html'),
];
$pipes = [];
$process = proc_open($command, [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes, __DIR__);
assertProductInventoryContext(is_resource($process), 'No se inicio navegador headless.');
$browserOutput = stream_get_contents($pipes[1]);
$browserErrors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$browserExit = proc_close($process);
removeContextProfile($profile);
assertProductInventoryContext(
    $browserExit === 0,
    "El navegador fallo ({$browserExit}): {$browserErrors}"
);
assertProductInventoryContext(
    preg_match(
        '/<pre id="test-results" data-status="([^"]+)">([^<]*)<\/pre>/',
        $browserOutput,
        $browserMatches
    ) === 1,
    'El harness no publico resultado.'
);
$browserStatus = html_entity_decode(
    $browserMatches[1],
    ENT_QUOTES | ENT_HTML5,
    'UTF-8'
);
$browserPayload = json_decode(html_entity_decode(
    $browserMatches[2],
    ENT_QUOTES | ENT_HTML5,
    'UTF-8'
), true);
assertProductInventoryContext(
    $browserStatus === 'pass' && is_array($browserPayload),
    'Fallo navegador: ' . wp_json_encode($browserPayload)
);

foreach (['modules', 'parser', 'urls', 'product_errors', 'filtered_list', 'create_context', 'duplicate', 'cancel'] as $scenario) {
    assertProductInventoryContext(
        ($browserPayload['scenarios'][$scenario] ?? false) === true,
        "No paso escenario navegador {$scenario}."
    );
}

foreach (
    [
        'product-catalog-selects-test.html',
        'product-form-save-ux-test.html',
        'product-media-selector-test.html',
        'product-unsaved-changes-test.html',
    ] as $productHarness
) {
    $productProfile = sys_get_temp_dir()
        . '/veciahorra-product-regression-'
        . getmypid()
        . '-'
        . md5($productHarness);
    $productCommand = [
        contextBrowser(),
        '--headless=new',
        '--disable-gpu',
        '--no-first-run',
        '--no-default-browser-check',
        '--allow-file-access-from-files',
        '--user-data-dir=' . $productProfile,
        '--virtual-time-budget=16000',
        '--dump-dom',
        contextFileUrl(__DIR__ . '/' . $productHarness),
    ];
    $productPipes = [];
    $productProcess = proc_open($productCommand, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $productPipes, __DIR__);
    assertProductInventoryContext(
        is_resource($productProcess),
        "No se inicio {$productHarness}."
    );
    $productOutput = stream_get_contents($productPipes[1]);
    $productErrors = stream_get_contents($productPipes[2]);
    fclose($productPipes[1]);
    fclose($productPipes[2]);
    $productExit = proc_close($productProcess);
    removeContextProfile($productProfile);
    preg_match('/<pre[^>]+id="result"[^>]*>(.*?)<\/pre>/s', $productOutput, $resultMatches);
    $productResult = html_entity_decode(
        strip_tags($resultMatches[1] ?? ''),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
    assertProductInventoryContext(
        $productExit === 0 && str_contains($productOutput, '<title>PASS</title>'),
        "Fallo {$productHarness} ({$productExit}): {$productErrors}\n"
            . $productResult
    );
}

echo "PASS product-inventory-context-test (browser=8 scenarios, product_harnesses=4)\n";
