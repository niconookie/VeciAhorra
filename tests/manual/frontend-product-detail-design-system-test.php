<?php

declare(strict_types=1);

use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;

ini_set('session.save_path', sys_get_temp_dir());
require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertProductDetailDesign(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, string> */
function productDetailSources(string $root): array
{
    $paths = [
        'view' => 'app/Modules/Frontend/Views/product-detail.php',
        'script' => 'assets/frontend/js/veciahorra-product-offers.js',
        'layout' => 'app/Modules/Frontend/Views/layout.php',
        'catalog_view' => 'app/Modules/Frontend/Views/catalog.php',
        'legacy_css' => 'assets/frontend/css/veciahorra-frontend.css',
        'design_css' => 'assets/frontend/css/veciahorra-design-system.css',
        'assets' => 'app/Modules/Frontend/Assets/FrontendAssets.php',
        'controller' => 'app/Modules/Frontend/Controller/FrontendController.php',
        'phase1_harness' => 'tests/manual/frontend-design-system-test.php',
        'catalog_script' => 'assets/frontend/js/veciahorra-catalog.js',
        'phase2_browser' => 'tests/manual/catalog-filter-alignment-browser-test.py',
        'phase2_harness' => 'tests/manual/frontend-catalog-design-system-test.php',
        'cart' => 'app/Modules/Frontend/Views/cart.php',
        'checkout' => 'app/Modules/Frontend/Views/checkout.php',
        'catalog_service' => 'app/Modules/Catalog/Service/CatalogService.php',
    ];
    $sources = [];
    foreach ($paths as $name => $path) {
        $contents = file_get_contents($root . '/' . $path);
        assertProductDetailDesign(is_string($contents) && $contents !== '', "PRODUCT_DETAIL_SOURCE: {$path}");
        $sources[$name] = $contents;
    }
    return $sources;
}

/** @param array<string, string> $sources */
function validateProductDetailDesign(array $sources): void
{
    assertProductDetailDesign(! str_contains($sources['layout'], 'va-design-system'), 'PRODUCT_DETAIL_ROOT_IN_LAYOUT');
    assertProductDetailDesign(substr_count($sources['catalog_view'], 'class="veciahorra-frontend va-design-system va-catalog"') === 1, 'PRODUCT_DETAIL_PHASE2_CHANGED');
    assertProductDetailDesign(! str_contains($sources['legacy_css'], '.veciahorra-frontend.va-design-system .va-product-detail'), 'PRODUCT_DETAIL_WRONG_DESCENDANT_SELECTOR');
    assertProductDetailDesign(! preg_match('/(?:^|})\s*(?:body|html|:root|\*)[^\{]*\{[^}]*PRODUCT_DETAIL_TEST/m', $sources['legacy_css']), 'PRODUCT_DETAIL_GLOBAL_SELECTOR');

    $root = '<article class="veciahorra-frontend va-design-system va-product-detail" data-va-product-detail data-product-id="<?php echo esc_attr((string) $productId); ?>" aria-labelledby="<?php echo esc_attr($titleId); ?>">';
    assertProductDetailDesign(str_contains($sources['view'], 'data-product-id='), 'PRODUCT_DETAIL_PRODUCT_ID_REMOVED');
    assertProductDetailDesign(str_contains($sources['view'], 'data-product-id="<?php echo esc_attr((string) $productId); ?>"'), 'PRODUCT_DETAIL_PRODUCT_ID_CHANGED');
    assertProductDetailDesign(str_contains($sources['view'], 'aria-labelledby="<?php echo esc_attr($titleId); ?>"'), 'PRODUCT_DETAIL_ARIA_LABELLEDBY_REMOVED');
    assertProductDetailDesign(str_contains($sources['view'], '<h1 id="<?php echo esc_attr($titleId); ?>" data-va-product-name>'), 'PRODUCT_DETAIL_HEADING_ID_REMOVED');
    assertProductDetailDesign(substr_count($sources['view'], $root) === 1, 'PRODUCT_DETAIL_ROOT_MISSING');
    assertProductDetailDesign(str_contains($sources['view'], 'va-product-detail__intro va-section-heading') && str_contains($sources['view'], 'va-product-detail__eyebrow va-eyebrow'), 'PRODUCT_DETAIL_HEADING_COMPONENT_MISSING');
    foreach (['va-product-detail__media va-card', 'va-offer-section va-card', 'va-selection-summary va-card', 'va-cart-action va-card'] as $class) {
        assertProductDetailDesign(str_contains($sources['view'], $class), 'PRODUCT_DETAIL_CARD_COMPONENT_MISSING');
    }
    assertProductDetailDesign(str_contains($sources['view'], 'va-button va-button--primary va-cart-action__button'), 'PRODUCT_DETAIL_PRIMARY_MAPPING');
    assertProductDetailDesign(str_contains($sources['view'], 'role="radiogroup"') && str_contains($sources['script'], "button.setAttribute('role', 'radio')"), 'PRODUCT_DETAIL_RADIO_SEMANTICS_REMOVED');
    assertProductDetailDesign(str_contains($sources['script'], "button.setAttribute('aria-checked', selected ? 'true' : 'false')"), 'PRODUCT_DETAIL_ARIA_CHECKED_REMOVED');
    assertProductDetailDesign(str_contains($sources['script'], "button.className = 'va-card va-offer-card'") && str_contains($sources['script'], "button.type = 'button'"), 'PRODUCT_DETAIL_OFFER_BUTTON_CHANGED');
    assertProductDetailDesign(str_contains($sources['script'], "button.setAttribute('data-inventory-id', String(offer.inventory_id))"), 'PRODUCT_DETAIL_INVENTORY_ID_REMOVED');
    assertProductDetailDesign(str_contains($sources['script'], "config.api.get('/catalog/products/' + productId)"), 'PRODUCT_DETAIL_ENDPOINT_CHANGED');
    assertProductDetailDesign(preg_match('/inventory_id:\s*selectedId\s*,/', $sources['script']) === 1, 'PRODUCT_DETAIL_INVENTORY_ID_MISMATCH');
    assertProductDetailDesign(str_contains($sources['script'], "config.api.post('/cart/items', {") && preg_match('/quantity:\s*1\s*\n\s*\}/', $sources['script']) === 1, 'PRODUCT_DETAIL_CART_FLOW_CHANGED');
    assertProductDetailDesign(str_contains($sources['script'], 'payload.inventory_id !== selectedId') && str_contains($sources['script'], 'Object.keys(payload).length !== 2'), 'PRODUCT_DETAIL_CART_PAYLOAD_CHANGED');
    assertProductDetailDesign(str_contains($sources['script'], 'if (isAddingToCart || !selectedExists)') && str_contains($sources['view'], 'data-va-add-to-cart disabled'), 'PRODUCT_DETAIL_UNSELECTED_CART');
    assertProductDetailDesign(str_contains($sources['view'], 'role="status" data-va-product-loading') && str_contains($sources['view'], 'role="alert" data-va-product-error'), 'PRODUCT_DETAIL_STATUS_SEMANTICS_REMOVED');
    assertProductDetailDesign(str_contains($sources['view'], 'aria-live="polite" data-va-selection-summary') && str_contains($sources['view'], 'aria-live="polite" data-va-cart-success'), 'PRODUCT_DETAIL_LIVE_REGION_REMOVED');
    foreach (['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', "event.key === 'Home'", "event.key === 'End'", "event.key === ' '", "event.key === 'Enter'"] as $key) {
        assertProductDetailDesign(str_contains($sources['script'], $key), 'PRODUCT_DETAIL_KEYBOARD_REMOVED');
    }
    assertProductDetailDesign(str_contains($sources['design_css'], '.va-button:focus-visible,') && str_contains($sources['legacy_css'], '.va-offer-card:focus-visible'), 'PRODUCT_DETAIL_FOCUS_REMOVED');
    assertProductDetailDesign(str_contains($sources['design_css'], 'min-width: 2.75rem;') && str_contains($sources['design_css'], 'min-height: 2.75rem;'), 'PRODUCT_DETAIL_TOUCH_TARGET_REDUCED');
    assertProductDetailDesign(! preg_match('/prestador|service_provider|whatsapp|contact_email|tel[eé]fono/i', $sources['view'] . $sources['script']), 'PRODUCT_DETAIL_PROVIDER_DISCLOSURE');
    assertProductDetailDesign(! preg_match('/Temporada|m[aá]s vendidos|promoci[oó]n temporal|barra inferior|buscador unificado/i', $sources['view'] . $sources['script']), 'PRODUCT_DETAIL_FUTURE_FEATURE');

    $immutable = [
        'assets' => '377e4800fefaeb359dfd2ff1ba599e44f76b95d0b92f43aa35e717b9ff013885',
        'design_css' => '0a95b693528efd2ba84198de3e0535726b99a4ca4032c16746530f1585f10635',
        'phase1_harness' => '44fef3e590fda5fa0ff43fa7924a7e03b2fbf496c821c5537f4e58438f39f804',
        'controller' => '79f958580e0d9905b16b0dbf4580a2fa1203b4ef48cc193355adc02b6e84e686',
        'catalog_view' => '1e488ce8496f9d4bea1ebac10f1f6666a7b45fdb11dff6951836ec192004196e',
        'legacy_css' => '27836bfc65b6628ac3e886441ea29afacc90c5036a5437f59607bbaa53cc0311',
        'catalog_script' => '648da8d33b22a6e967395d6f5e204c3de61d26f5e032745a512b292d474d842c',
        'phase2_browser' => '1330fcb697c12dd5825cf8b0395850adf7efecf3d753a0b1157296e26c300c07',
        'phase2_harness' => '19be489ca0c317b5aeb0dbe3051017921528b187e165f80f08f41acef7c96e1c',
    ];
    foreach ($immutable as $name => $hash) {
        assertProductDetailDesign(hash('sha256', $sources[$name]) === $hash, str_starts_with($name, 'catalog') || str_starts_with($name, 'phase2') || $name === 'legacy_css' ? 'PRODUCT_DETAIL_PHASE2_CHANGED' : 'PRODUCT_DETAIL_PHASE1_CHANGED');
    }
    assertProductDetailDesign(hash('sha256', $sources['cart']) === '216db4ec740f1b28b21bdec5c0d008924a34e1f57e90453f06fafc8057e9e3a9' && hash('sha256', $sources['checkout']) === '941a6d268343d831252fae90fc9478d923b682e3b6d5d4a04f09dfb730ad3670', 'PRODUCT_DETAIL_ADJACENT_SURFACE_CHANGED');
    assertProductDetailDesign(hash('sha256', $sources['catalog_service']) === 'b2357ebefa74f94e6b6850659884f4a6a337953aa65784acf577877fcd415542', 'PRODUCT_DETAIL_SECTORIZATION_CHANGED');
}

function productDetailMutate(string $source, string $search, string $replace): string
{
    assertProductDetailDesign(substr_count($source, $search) === 1, "PRODUCT_DETAIL_MUTATION_NOT_UNITARY: {$search}");
    return str_replace($search, $replace, $source);
}

/** @param array<string, string> $sources @return array{string,string,string} */
function expectProductDetailRejection(array $sources, string $expected, string $label): array
{
    try {
        validateProductDetailDesign($sources);
    } catch (RuntimeException $exception) {
        assertProductDetailDesign($exception->getMessage() === $expected, "PRODUCT_DETAIL_WRONG_DIAGNOSTIC: {$label}; expected={$expected}; obtained={$exception->getMessage()}");
        return [$label, $expected, $exception->getMessage()];
    }
    throw new RuntimeException("PRODUCT_DETAIL_ADVERSARIAL_ACCEPTED: {$label}");
}

/** @param list<string> $paths */
function validateProductDetailScope(array $paths): void
{
    sort($paths, SORT_STRING);
    $allowed = [
        'app/Modules/Frontend/Views/product-detail.php',
        'assets/frontend/js/veciahorra-product-offers.js',
        'tests/manual/frontend-product-detail-design-system-test.php',
        'tests/manual/product-detail-design-system-browser-test.py',
    ];
    sort($allowed, SORT_STRING);
    assertProductDetailDesign($paths === $allowed, 'PRODUCT_DETAIL_OUT_OF_SCOPE_FILE');
}

$root = dirname(__DIR__, 2);
$sources = productDetailSources($root);
validateProductDetailDesign($sources);
$cases = [
    ['view', 'veciahorra-frontend va-design-system va-product-detail', 'va-product-detail', 'PRODUCT_DETAIL_ROOT_MISSING', 'raiz ausente'],
    ['layout', 'class="veciahorra-frontend"', 'class="veciahorra-frontend va-design-system"', 'PRODUCT_DETAIL_ROOT_IN_LAYOUT', 'raiz en layout'],
    ['legacy_css', '/* Phase 2 public catalog design-system bridge. */', "/* Phase 2 public catalog design-system bridge. */\n.veciahorra-frontend.va-design-system .va-product-detail { color: red; }", 'PRODUCT_DETAIL_WRONG_DESCENDANT_SELECTOR', 'selector descendiente'],
    ['legacy_css', '/* Phase 2 public catalog design-system bridge. */', "/* Phase 2 public catalog design-system bridge. */\nbody { --PRODUCT_DETAIL_TEST: 1; }", 'PRODUCT_DETAIL_GLOBAL_SELECTOR', 'selector global'],
    ['view', ' data-product-id="<?php echo esc_attr((string) $productId); ?>"', '', 'PRODUCT_DETAIL_PRODUCT_ID_REMOVED', 'product id ausente'],
    ['view', 'esc_attr((string) $productId)', 'esc_attr((string) ($productId + 1))', 'PRODUCT_DETAIL_PRODUCT_ID_CHANGED', 'product id alterado'],
    ['view', ' aria-labelledby="<?php echo esc_attr($titleId); ?>"', '', 'PRODUCT_DETAIL_ARIA_LABELLEDBY_REMOVED', 'labelledby ausente'],
    ['view', '<h1 id="<?php echo esc_attr($titleId); ?>" data-va-product-name>', '<h1 data-va-product-name>', 'PRODUCT_DETAIL_HEADING_ID_REMOVED', 'heading id ausente'],
    ['script', "button.className = 'va-card va-offer-card'", "button.className = 'va-offer-card'", 'PRODUCT_DETAIL_OFFER_BUTTON_CHANGED', 'card dinamica'],
    ['script', "button.setAttribute('data-inventory-id', String(offer.inventory_id))", "button.setAttribute('data-product-id', String(offer.product_id))", 'PRODUCT_DETAIL_INVENTORY_ID_REMOVED', 'inventory id ausente'],
    ['script', "config.api.get('/catalog/products/' + productId)", "config.api.get('/catalog/items/' + productId)", 'PRODUCT_DETAIL_ENDPOINT_CHANGED', 'endpoint alterado'],
    ['script', 'inventory_id: selectedId', 'inventory_id: selectedId + 1', 'PRODUCT_DETAIL_INVENTORY_ID_MISMATCH', 'inventory id sustituido'],
    ['script', "quantity: 1\r\n            }, cartRequestOptions())", "quantity: 2\r\n            }, cartRequestOptions())", 'PRODUCT_DETAIL_CART_FLOW_CHANGED', 'cantidad alterada'],
    ['script', "button.type = 'button'", "button.type = 'submit'", 'PRODUCT_DETAIL_OFFER_BUTTON_CHANGED', 'tipo de oferta'],
    ['script', "button.setAttribute('role', 'radio')", "button.setAttribute('role', 'button')", 'PRODUCT_DETAIL_RADIO_SEMANTICS_REMOVED', 'radio eliminado'],
    ['script', "button.setAttribute('aria-checked', selected ? 'true' : 'false')", "button.setAttribute('aria-pressed', selected ? 'true' : 'false')", 'PRODUCT_DETAIL_ARIA_CHECKED_REMOVED', 'aria checked eliminado'],
    ['view', 'aria-live="polite" data-va-selection-summary', 'data-va-selection-summary', 'PRODUCT_DETAIL_LIVE_REGION_REMOVED', 'live region eliminada'],
    ['script', "event.key === 'ArrowRight'", "event.key === 'PageDown'", 'PRODUCT_DETAIL_KEYBOARD_REMOVED', 'teclado eliminado'],
    ['design_css', '.va-button:focus-visible,', '.va-button:focus-within,', 'PRODUCT_DETAIL_FOCUS_REMOVED', 'foco eliminado'],
    ['design_css', 'min-width: 2.75rem;', 'min-width: 2rem;', 'PRODUCT_DETAIL_TOUCH_TARGET_REDUCED', 'target reducido'],
    ['view', "esc_html_e('Producto', 'veciahorra')", "esc_html_e('WhatsApp del prestador', 'veciahorra')", 'PRODUCT_DETAIL_PROVIDER_DISCLOSURE', 'prestador revelado'],
    ['view', "esc_html_e('Producto', 'veciahorra')", "esc_html_e('Temporada', 'veciahorra')", 'PRODUCT_DETAIL_FUTURE_FEATURE', 'funcion futura'],
    ['design_css', '--va-color-primary:', '--va-color-primary: /* changed */', 'PRODUCT_DETAIL_PHASE1_CHANGED', 'fase 1 alterada'],
    ['catalog_view', 'Compra local, ahorra cerca', 'Catálogo alterado', 'PRODUCT_DETAIL_PHASE2_CHANGED', 'fase 2 alterada'],
    ['catalog_service', 'private function publicInventory', 'private function changedPublicInventory', 'PRODUCT_DETAIL_SECTORIZATION_CHANGED', 'sectorizacion alterada'],
    ['cart', 'class="va-public-cart"', 'class="va-public-cart va-design-system"', 'PRODUCT_DETAIL_ADJACENT_SURFACE_CHANGED', 'carrito alterado'],
];
$adversarials = [];
foreach ($cases as [$file, $search, $replace, $diagnostic, $label]) {
    $candidate = $sources;
    $candidate[$file] = productDetailMutate($candidate[$file], $search, $replace);
    $adversarials[] = expectProductDetailRejection($candidate, $diagnostic, $label);
}
$adversarials[] = (static function (): array {
    try {
        validateProductDetailScope([
            'app/Modules/Frontend/Views/product-detail.php',
            'assets/frontend/js/veciahorra-product-offers.js',
            'tests/manual/frontend-product-detail-design-system-test.php',
            'tests/manual/product-detail-design-system-browser-test.py',
            'assets/frontend/css/veciahorra-frontend.css',
        ]);
    } catch (RuntimeException $exception) {
        assertProductDetailDesign($exception->getMessage() === 'PRODUCT_DETAIL_OUT_OF_SCOPE_FILE', 'PRODUCT_DETAIL_WRONG_DIAGNOSTIC: archivo fuera de alcance');
        return ['archivo fuera de alcance', 'PRODUCT_DETAIL_OUT_OF_SCOPE_FILE', $exception->getMessage()];
    }
    throw new RuntimeException('PRODUCT_DETAIL_ADVERSARIAL_ACCEPTED: archivo fuera de alcance');
})();
assertProductDetailDesign(count($adversarials) === 27, 'PRODUCT_DETAIL_ADVERSARIAL_COUNT');

$markup = do_shortcode('[veciahorra_frontend product_id="1000000852"]');
assertProductDetailDesign(str_contains($markup, 'class="veciahorra-frontend va-design-system va-product-detail" data-va-product-detail data-product-id="1000000852"'), 'PRODUCT_DETAIL_RUNTIME_ROOT');
assertProductDetailDesign(preg_match('/<article[^>]+aria-labelledby="([^"]+)"[^>]*>.*?<h1 id="\1"/s', $markup) === 1, 'PRODUCT_DETAIL_RUNTIME_LABEL');
global $wp_styles, $wp_scripts;
assertProductDetailDesign(wp_style_is(FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE, 'enqueued') && wp_style_is(FrontendAssets::STYLE_HANDLE, 'enqueued') && wp_script_is(FrontendAssets::SCRIPT_HANDLE, 'enqueued') && wp_script_is(FrontendAssets::OFFER_SCRIPT_HANDLE, 'enqueued'), 'PRODUCT_DETAIL_RUNTIME_ASSETS');
assertProductDetailDesign(count(array_keys($wp_scripts->queue, FrontendAssets::OFFER_SCRIPT_HANDLE, true)) === 1, 'PRODUCT_DETAIL_RUNTIME_IDEMPOTENCE');

$untracked = array_values(array_filter(preg_split('/\R/', trim((string) shell_exec('git ls-files --others --exclude-standard'))) ?: []));
$new = ['tests/manual/frontend-product-detail-design-system-test.php', 'tests/manual/product-detail-design-system-browser-test.py'];
$environmental = array_values(array_diff($untracked, $new));
sort($environmental, SORT_STRING);
assertProductDetailDesign(count($environmental) === 519 && hash('sha256', implode("\n", $environmental)) === '15a45f3aa19cacb8be80b0963476671e388e75501ff5088f839c385bf1d1433d', 'PRODUCT_DETAIL_ENVIRONMENT');
$phaseDiff = array_values(array_filter(preg_split('/\R/', trim((string) shell_exec('git diff --name-only 4c838c806ccd83ff7c5a7095ce0069c0787c7a22'))) ?: []));
foreach ($new as $path) {
    if (in_array($path, $untracked, true)) {
        $phaseDiff[] = $path;
    }
}
validateProductDetailScope(array_values(array_unique($phaseDiff)));

$artifactFiles = $artifactDirs = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/artifacts', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($iterator as $entry) {
    $entry->isDir() ? $artifactDirs[] = $entry : $artifactFiles[] = $entry;
}
assertProductDetailDesign(count($artifactFiles) === 513 && count($artifactDirs) === 309 && array_sum(array_map(static fn (SplFileInfo $file): int => $file->getSize(), $artifactFiles)) === 28537157, 'PRODUCT_DETAIL_ARTIFACTS');

foreach ($adversarials as [$label, $expected, $obtained]) {
    printf("ADVERSARIAL label=%s expected=%s obtained=%s\n", $label, $expected, $obtained);
}
printf("PASS frontend-product-detail-design-system-test adversarials=%d root=same-node runtime=pass\n", count($adversarials));
