<?php

declare(strict_types=1);

use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;

ini_set('session.save_path', sys_get_temp_dir());
require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertCatalogDesign(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, string> */
function catalogDesignSources(string $root): array
{
    $paths = [
        'view' => 'app/Modules/Frontend/Views/catalog.php',
        'script' => 'assets/frontend/js/veciahorra-catalog.js',
        'legacy_css' => 'assets/frontend/css/veciahorra-frontend.css',
        'layout' => 'app/Modules/Frontend/Views/layout.php',
        'product' => 'app/Modules/Frontend/Views/product-detail.php',
        'cart' => 'app/Modules/Frontend/Views/cart.php',
        'checkout' => 'app/Modules/Frontend/Views/checkout.php',
        'assets' => 'app/Modules/Frontend/Assets/FrontendAssets.php',
        'controller' => 'app/Modules/Frontend/Controller/FrontendController.php',
        'design_css' => 'assets/frontend/css/veciahorra-design-system.css',
        'phase1_harness' => 'tests/manual/frontend-design-system-test.php',
    ];
    $sources = [];
    foreach ($paths as $name => $path) {
        $contents = file_get_contents($root . '/' . $path);
        assertCatalogDesign(is_string($contents) && $contents !== '', "CATALOG_SOURCE: {$path}.");
        $sources[$name] = $contents;
    }
    return $sources;
}

/** @param array<string, string> $sources */
function validateCatalogDesign(array $sources): void
{
    $rootClass = 'class="veciahorra-frontend va-design-system va-catalog" data-va-catalog';
    assertCatalogDesign(substr_count($sources['view'], $rootClass) === 1, 'CATALOG_ROOT_MISSING');
    foreach (['layout', 'product', 'cart', 'checkout'] as $surface) {
        assertCatalogDesign(! str_contains($sources[$surface], 'va-design-system'), 'CATALOG_ROOT_IN_' . strtoupper($surface));
    }

    $bridgeStart = '/* Phase 2 public catalog design-system bridge. */';
    $bridgeEnd = '/* End Phase 2 public catalog design-system bridge. */';
    assertCatalogDesign(substr_count($sources['legacy_css'], $bridgeStart) === 1 && substr_count($sources['legacy_css'], $bridgeEnd) === 1, 'CATALOG_BRIDGE_DELIMITATION');
    $bridge = strstr($sources['legacy_css'], $bridgeStart);
    assertCatalogDesign(is_string($bridge), 'CATALOG_BRIDGE_DELIMITATION');
    $bridge = strstr($bridge, $bridgeEnd, true);
    assertCatalogDesign(is_string($bridge), 'CATALOG_BRIDGE_DELIMITATION');
    assertCatalogDesign(! str_contains($bridge, '.veciahorra-frontend.va-design-system .va-catalog'), 'CATALOG_WRONG_DESCENDANT_SELECTOR');
    assertCatalogDesign(! preg_match('/(?:^|})\s*(?:body|html|:root|\*|\.ct-|\.woocommerce)\b/m', $bridge), 'CATALOG_GLOBAL_SELECTOR');
    assertCatalogDesign(! preg_match('/\.veciahorra-frontend\.va-design-system\.va-catalog\s*[+~]/', $bridge), 'CATALOG_SIBLING_COMBINATOR');
    foreach (preg_split('/\R/', $bridge) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '/*') || str_starts_with($line, '@') || str_starts_with($line, '}') || str_contains($line, ': ') || str_ends_with($line, ';')) {
            continue;
        }
        if (str_ends_with($line, '{') || str_ends_with($line, ',')) {
            assertCatalogDesign(str_starts_with($line, '.veciahorra-frontend.va-design-system.va-catalog'), 'CATALOG_GLOBAL_SELECTOR');
        }
    }

    assertCatalogDesign(str_contains($sources['script'], "el('article', 'va-card va-catalog-card')"), 'CATALOG_CARD_COMPONENT_MISSING');
    assertCatalogDesign(str_contains($sources['view'], 'class="va-button va-button--primary" type="submit"'), 'CATALOG_PRIMARY_MAPPING');
    assertCatalogDesign(str_contains($sources['view'], 'class="va-button va-button--secondary" type="button" data-va-catalog-reset'), 'CATALOG_SECONDARY_MAPPING');
    assertCatalogDesign(str_contains($sources['script'], "el('a', 'va-button va-button--primary va-catalog-card__action', 'Ver producto')") && str_contains($sources['script'], 'link.href = url;'), 'CATALOG_DETAIL_LINK_REMOVED');
    assertCatalogDesign(! str_contains($sources['script'], "/cart/items") && ! preg_match('/\.post\s*\(/', $sources['script']), 'CATALOG_DIRECT_CART');
    assertCatalogDesign(str_contains($sources['script'], "url.searchParams.set('product_id', id)"), 'CATALOG_PRODUCT_ID_REMOVED');
    assertCatalogDesign(str_contains($sources['view'], '<label for="<?php echo esc_attr($instanceId . \'-search\'); ?>">'), 'CATALOG_LABEL_REMOVED');
    assertCatalogDesign(str_contains($sources['view'], 'role="alert" data-va-catalog-error'), 'CATALOG_ALERT_ROLE_REMOVED');
    assertCatalogDesign(str_contains($sources['view'], 'role="status" aria-live="polite" aria-atomic="true" data-va-catalog-status'), 'CATALOG_LIVE_REGION_REMOVED');
    assertCatalogDesign(str_contains($sources['design_css'], '.va-button:focus-visible,') && str_contains($sources['design_css'], ':is(input, select, textarea):focus-visible'), 'CATALOG_FOCUS_REMOVED');
    assertCatalogDesign(str_contains($sources['design_css'], 'min-width: 2.75rem;') && str_contains($sources['design_css'], 'min-height: 2.75rem;'), 'CATALOG_TOUCH_TARGET_REDUCED');
    assertCatalogDesign(! preg_match('/Temporada|m[aá]s vendidos|promoci[oó]n temporal|oferta temporal/i', $sources['view'] . $sources['script'] . $bridge), 'CATALOG_FUTURE_FEATURE');
    assertCatalogDesign(! preg_match('/prestador|service_provider|whatsapp|contact_email|tel[eé]fono del prestador/i', $sources['view'] . $sources['script'] . $bridge), 'CATALOG_PROVIDER_DISCLOSURE');
    assertCatalogDesign(str_contains($sources['script'], "'/catalog/products?'") && str_contains($sources['script'], "'/catalog/categories'"), 'CATALOG_ENDPOINT_CHANGED');

    $immutable = [
        'assets' => '377e4800fefaeb359dfd2ff1ba599e44f76b95d0b92f43aa35e717b9ff013885',
        'controller' => '79f958580e0d9905b16b0dbf4580a2fa1203b4ef48cc193355adc02b6e84e686',
        'design_css' => '0a95b693528efd2ba84198de3e0535726b99a4ca4032c16746530f1585f10635',
        'phase1_harness' => '44fef3e590fda5fa0ff43fa7924a7e03b2fbf496c821c5537f4e58438f39f804',
        'product' => '0dfa00f0655f08187ca7b23fd9e48d254e26a2ef34a831ca2255343833fcec83',
        'cart' => '216db4ec740f1b28b21bdec5c0d008924a34e1f57e90453f06fafc8057e9e3a9',
        'checkout' => '941a6d268343d831252fae90fc9478d923b682e3b6d5d4a04f09dfb730ad3670',
    ];
    assertCatalogDesign(hash('sha256', $sources['layout']) === '7202580de4b7f8fbfa66003d42a6e9e7a2259e3682869164bcc7ae36d6f40537', 'CATALOG_SECTORIZATION_CHANGED');
    foreach ($immutable as $name => $hash) {
        assertCatalogDesign(hash('sha256', $sources[$name]) === $hash, 'CATALOG_PHASE1_ASSET_CHANGED');
    }
}

function catalogMutateOnce(string $source, string $search, string $replace): string
{
    assertCatalogDesign(substr_count($source, $search) === 1, "CATALOG_MUTATION_NOT_UNITARY: {$search}");
    $mutated = str_replace($search, $replace, $source);
    assertCatalogDesign($mutated !== $source, 'CATALOG_MUTATION_NO_EFFECT');
    return $mutated;
}

/** @param array<string, string> $sources */
function expectCatalogRejection(array $sources, string $expected, string $label): array
{
    try {
        validateCatalogDesign($sources);
    } catch (RuntimeException $exception) {
        assertCatalogDesign(str_contains($exception->getMessage(), $expected), "CATALOG_WRONG_DIAGNOSTIC: {$label}; esperado={$expected}; obtenido={$exception->getMessage()}");
        return [$label, $expected, $exception->getMessage()];
    } catch (Throwable $exception) {
        throw new RuntimeException("CATALOG_UNEXPECTED_EXCEPTION: {$label}; " . $exception::class . ': ' . $exception->getMessage(), 0, $exception);
    }
    throw new RuntimeException("CATALOG_ADVERSARIAL_ACCEPTED: {$label}");
}

/** @param list<string> $paths */
function validateCatalogScope(array $paths): void
{
    sort($paths, SORT_STRING);
    $allowed = [
        'app/Modules/Frontend/Views/catalog.php',
        'assets/frontend/css/veciahorra-frontend.css',
        'assets/frontend/js/veciahorra-catalog.js',
        'tests/manual/catalog-filter-alignment-browser-test.py',
        'tests/manual/frontend-catalog-design-system-test.php',
    ];
    sort($allowed, SORT_STRING);
    assertCatalogDesign($paths === $allowed, 'CATALOG_OUT_OF_SCOPE_FILE');
}

$root = dirname(__DIR__, 2);
$sources = catalogDesignSources($root);
validateCatalogDesign($sources);

$adversarials = [];
$cases = [
    ['view', 'veciahorra-frontend va-design-system va-catalog', 'veciahorra-frontend va-catalog', 'CATALOG_ROOT_MISSING', 'raiz ausente'],
    ['layout', 'class="veciahorra-frontend"', 'class="veciahorra-frontend va-design-system"', 'CATALOG_ROOT_IN_LAYOUT', 'raiz en layout'],
    ['product', 'class="va-product-detail"', 'class="va-product-detail va-design-system"', 'CATALOG_ROOT_IN_PRODUCT', 'raiz en ficha'],
    ['cart', 'class="va-public-cart"', 'class="va-public-cart va-design-system"', 'CATALOG_ROOT_IN_CART', 'raiz en carrito'],
    ['checkout', 'class="va-checkout"', 'class="va-checkout va-design-system"', 'CATALOG_ROOT_IN_CHECKOUT', 'raiz en checkout'],
    ['legacy_css', '.veciahorra-frontend.va-design-system.va-catalog .va-catalog__filters', '.veciahorra-frontend.va-design-system .va-catalog .va-catalog__filters', 'CATALOG_WRONG_DESCENDANT_SELECTOR', 'selector descendiente'],
    ['legacy_css', '/* Phase 2 public catalog design-system bridge. */', "/* Phase 2 public catalog design-system bridge. */\nbody { color: red; }", 'CATALOG_GLOBAL_SELECTOR', 'selector global'],
    ['legacy_css', '/* Phase 2 public catalog design-system bridge. */', "/* Phase 2 public catalog design-system bridge. */\n.veciahorra-frontend.va-design-system.va-catalog + body { color: red; }", 'CATALOG_SIBLING_COMBINATOR', 'hermano externo'],
    ['design_css', '--va-color-primary:', '--va-color-primary: /* changed */', 'CATALOG_PHASE1_ASSET_CHANGED', 'asset Fase 1'],
    ['script', "el('article', 'va-card va-catalog-card')", "el('article', 'va-catalog-card')", 'CATALOG_CARD_COMPONENT_MISSING', 'tarjeta sin componente'],
    ['view', 'va-button va-button--primary" type="submit"', 'va-button" type="submit"', 'CATALOG_PRIMARY_MAPPING', 'aplicar sin primary'],
    ['view', 'va-button va-button--secondary" type="button" data-va-catalog-reset', 'va-button va-button--primary" type="button" data-va-catalog-reset', 'CATALOG_SECONDARY_MAPPING', 'restablecer primary'],
    ['script', 'link.href = url;', 'link.dataset.url = url;', 'CATALOG_DETAIL_LINK_REMOVED', 'enlace eliminado'],
    ['script', "    function mount(root) {\n        var loading = root.querySelector('[data-va-catalog-loading]');", "    function mount(root) {\n        config.api.post('/cart/items', {});\n        var loading = root.querySelector('[data-va-catalog-loading]');", 'CATALOG_DIRECT_CART', 'cart directo'],
    ['script', "url.searchParams.set('product_id', id)", "url.searchParams.set('item', id)", 'CATALOG_PRODUCT_ID_REMOVED', 'product id eliminado'],
    ['view', '<label for="<?php echo esc_attr($instanceId . \'-search\'); ?>">', '<span>', 'CATALOG_LABEL_REMOVED', 'label eliminado'],
    ['view', 'role="alert" data-va-catalog-error', 'data-va-catalog-error', 'CATALOG_ALERT_ROLE_REMOVED', 'alert eliminado'],
    ['view', 'role="status" aria-live="polite" aria-atomic="true" data-va-catalog-status', 'data-va-catalog-status', 'CATALOG_LIVE_REGION_REMOVED', 'live region eliminada'],
    ['design_css', '.va-button:focus-visible,', '.va-button:focus-within,', 'CATALOG_FOCUS_REMOVED', 'focus eliminado'],
    ['design_css', 'min-width: 2.75rem;', 'min-width: 2rem;', 'CATALOG_TOUCH_TARGET_REDUCED', 'touch target reducido'],
    ['view', 'Compra local, ahorra cerca', 'Temporada', 'CATALOG_FUTURE_FEATURE', 'funcion futura'],
    ['view', 'Compra local, ahorra cerca', 'WhatsApp del prestador', 'CATALOG_PROVIDER_DISCLOSURE', 'prestador revelado'],
    ['script', "'/catalog/categories'", "'/market/categories'", 'CATALOG_ENDPOINT_CHANGED', 'endpoint alterado'],
    ['layout', 'data-va-sector-selector', 'data-va-zone-selector', 'CATALOG_SECTORIZATION_CHANGED', 'sectorizacion alterada'],
];
foreach ($cases as [$file, $search, $replace, $diagnostic, $label]) {
    $candidate = $sources;
    $candidate[$file] = catalogMutateOnce($candidate[$file], $search, $replace);
    $adversarials[] = expectCatalogRejection($candidate, $diagnostic, $label);
}
$adversarials[] = (static function (): array {
    try {
        validateCatalogScope([
            'app/Modules/Frontend/Views/catalog.php',
            'assets/frontend/css/veciahorra-frontend.css',
            'assets/frontend/js/veciahorra-catalog.js',
            'tests/manual/catalog-filter-alignment-browser-test.py',
            'tests/manual/frontend-catalog-design-system-test.php',
            'app/Modules/Frontend/Views/layout.php',
        ]);
    } catch (RuntimeException $exception) {
        assertCatalogDesign(str_contains($exception->getMessage(), 'CATALOG_OUT_OF_SCOPE_FILE'), 'CATALOG_WRONG_DIAGNOSTIC: archivo fuera de alcance');
        return ['archivo fuera de alcance', 'CATALOG_OUT_OF_SCOPE_FILE', $exception->getMessage()];
    }
    throw new RuntimeException('CATALOG_ADVERSARIAL_ACCEPTED: archivo fuera de alcance');
})();
assertCatalogDesign(count($adversarials) === 25, 'CATALOG_ADVERSARIAL_COUNT');

$catalogMarkup = do_shortcode('[veciahorra_frontend]');
$productMarkup = do_shortcode('[veciahorra_frontend product_id="1"]');
assertCatalogDesign(str_contains($catalogMarkup, 'class="veciahorra-frontend va-design-system va-catalog"') && str_contains($catalogMarkup, 'data-va-catalog'), 'CATALOG_RUNTIME_ROOT');
assertCatalogDesign(! str_contains($productMarkup, 'va-design-system'), 'CATALOG_RUNTIME_PRODUCT_ISOLATION');
global $wp_styles;
$designPosition = array_search(FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE, $wp_styles->queue, true);
$legacyPosition = array_search(FrontendAssets::STYLE_HANDLE, $wp_styles->queue, true);
assertCatalogDesign(is_int($designPosition) && is_int($legacyPosition) && $legacyPosition < $designPosition, 'CATALOG_RUNTIME_STYLE_ORDER');

$untracked = array_values(array_filter(preg_split('/\R/', trim((string) shell_exec('git ls-files --others --exclude-standard'))) ?: []));
$environmental = array_values(array_diff($untracked, ['tests/manual/frontend-catalog-design-system-test.php']));
sort($environmental, SORT_STRING);
assertCatalogDesign(count($environmental) === 519 && hash('sha256', implode("\n", $environmental)) === '15a45f3aa19cacb8be80b0963476671e388e75501ff5088f839c385bf1d1433d', 'CATALOG_ENVIRONMENT');
$phaseDiff = array_values(array_filter(preg_split('/\R/', trim((string) shell_exec('git diff --name-only a7b11b05c1fecd43a23e81b5f4c7bc3ec488d3b0'))) ?: []));
if (in_array('tests/manual/frontend-catalog-design-system-test.php', $untracked, true)) {
    $phaseDiff[] = 'tests/manual/frontend-catalog-design-system-test.php';
}
validateCatalogScope(array_values(array_unique($phaseDiff)));

$artifactFiles = [];
$artifactDirectories = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/artifacts', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($iterator as $entry) {
    if ($entry->isDir()) {
        $artifactDirectories[] = $entry;
    } elseif ($entry->isFile()) {
        $artifactFiles[] = $entry;
    }
}
assertCatalogDesign(count($artifactFiles) === 513 && count($artifactDirectories) === 309 && array_sum(array_map(static fn (SplFileInfo $file): int => $file->getSize(), $artifactFiles)) === 28537157, 'CATALOG_ARTIFACTS');

foreach ($adversarials as [$label, $expected, $obtained]) {
    printf("ADVERSARIAL label=%s expected=%s obtained=%s\n", $label, $expected, $obtained);
}
printf("PASS frontend-catalog-design-system-test adversarials=%d root=same-node runtime=pass\n", count($adversarials));
