<?php

declare(strict_types=1);

use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;

ini_set('session.save_path', sys_get_temp_dir());
require_once dirname(__DIR__, 5) . '/wp-load.php';

const CART_PHASE4_BASELINE = '4d04357d72c6947f7bab8f3b7a70e903f431f2f7';

function assertCartDesign(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, string> */
function cartDesignSources(string $root): array
{
    $paths = [
        'view' => 'app/Modules/Frontend/Views/cart.php',
        'script' => 'assets/frontend/js/veciahorra-cart.js',
        'layout' => 'app/Modules/Frontend/Views/layout.php',
        'checkout' => 'app/Modules/Frontend/Views/checkout.php',
        'design_css' => 'assets/frontend/css/veciahorra-design-system.css',
        'legacy_css' => 'assets/frontend/css/veciahorra-frontend.css',
        'assets' => 'app/Modules/Frontend/Assets/FrontendAssets.php',
        'controller' => 'app/Modules/Frontend/Controller/FrontendController.php',
        'routes' => 'app/Modules/Cart/Routes/CartRoutes.php',
        'cart_controller' => 'app/Modules/Cart/Controller/CartController.php',
        'service' => 'app/Modules/Cart/Service/CartService.php',
        'repository' => 'app/Modules/Cart/Repository/CartRepository.php',
        'create_request' => 'app/Modules/Cart/Requests/CartItemCreateRequest.php',
        'quantity_request' => 'app/Modules/Cart/Requests/CartItemQuantityRequest.php',
        'session' => 'app/Modules/Frontend/Support/CartSession.php',
        'catalog_service' => 'app/Modules/Catalog/Service/CatalogService.php',
    ];
    $sources = [];
    foreach ($paths as $name => $path) {
        $contents = file_get_contents($root . '/' . $path);
        assertCartDesign(is_string($contents) && $contents !== '', "CART_SOURCE: {$path}");
        $sources[$name] = $contents;
    }
    return $sources;
}

/** @param array<string, string> $sources */
function validateCartDesign(array $sources): void
{
    $root = '<section class="veciahorra-frontend va-design-system va-public-cart"';
    assertCartDesign(substr_count($sources['view'], $root) === 1, 'CART_DESIGN_ROOT_MISSING');
    assertCartDesign(! str_contains($sources['layout'], 'va-design-system'), 'CART_ROOT_IN_LAYOUT');
    assertCartDesign(! str_contains($sources['checkout'], 'va-design-system'), 'CART_ROOT_IN_CHECKOUT');
    assertCartDesign(str_contains($sources['view'], 'data-va-cart aria-labelledby="<?php echo esc_attr($titleId); ?>"'), 'CART_ARIA_LABELLEDBY_REMOVED');
    assertCartDesign(str_contains($sources['view'], '<h1 id="<?php echo esc_attr($titleId); ?>">'), 'CART_HEADING_ID_REMOVED');
    assertCartDesign(str_contains($sources['view'], 'va-public-cart__header') && str_contains($sources['view'], 'va-section-heading') && str_contains($sources['view'], 'va-product-detail__eyebrow va-eyebrow'), 'CART_HEADING_COMPONENT_MISSING');
    assertCartDesign(str_contains($sources['view'], 'va-public-cart__content va-card'), 'CART_CARD_COMPONENT_MISSING');
    assertCartDesign(str_contains($sources['view'], 'va-button va-button--primary" type="button" data-va-cart-retry'), 'CART_RETRY_MAPPING');
    assertCartDesign(str_contains($sources['view'], 'va-button va-button--primary" href="<?php echo esc_url($checkoutUrl); ?>" data-va-cart-checkout'), 'CART_CHECKOUT_MAPPING');
    assertCartDesign(substr_count($sources['script'], "'va-button va-button--secondary va-cart-quantity__button'") === 2, 'CART_TOUCH_TARGET_REDUCED');

    foreach (['data-va-cart-loading', 'data-va-cart-error', 'data-va-cart-retry', 'data-va-cart-empty', 'data-va-cart-content', 'data-va-cart-items', 'data-va-cart-total', 'data-va-cart-clear'] as $mount) {
        assertCartDesign(str_contains($sources['view'], $mount), 'CART_MOUNT_REMOVED');
    }
    assertCartDesign(str_contains($sources['view'], '<table class="va-cart-table">') && substr_count($sources['view'], '<th scope="col">') === 6, 'CART_TABLE_SEMANTICS_REMOVED');
    assertCartDesign(str_contains($sources['view'], 'aria-live="polite" aria-atomic="true" data-va-cart-status'), 'CART_LIVE_REGION_REMOVED');
    assertCartDesign(str_contains($sources['script'], "decrease.setAttribute('aria-label'") && str_contains($sources['script'], "increase.setAttribute('aria-label'") && str_contains($sources['script'], "remove.setAttribute('aria-label'"), 'CART_QUANTITY_LABEL_REMOVED');
    assertCartDesign(str_contains($sources['design_css'], '.va-button:focus-visible,') && str_contains($sources['legacy_css'], '.va-cart-quantity__button:focus-visible'), 'CART_FOCUS_REMOVED');
    assertCartDesign(str_contains($sources['design_css'], 'min-width: 2.75rem;') && str_contains($sources['design_css'], 'min-height: 2.75rem;'), 'CART_TOUCH_TARGET_REDUCED');

    assertCartDesign(str_contains($sources['script'], "return apiRequest('get', '/cart').then(render).catch(showError);"), 'CART_GET_ENDPOINT_CHANGED');
    assertCartDesign(str_contains($sources['script'], "'/cart/items/' + encodeURIComponent(item.id)"), 'CART_ITEM_ID_CHANGED');
    assertCartDesign(str_contains($sources['script'], "return mutate(\n                'patch',") || str_contains($sources['script'], "return mutate(\r\n                'patch',"), 'CART_PATCH_ENDPOINT_CHANGED');
    assertCartDesign(str_contains($sources['script'], '{ quantity: quantity }'), 'CART_PATCH_PAYLOAD_CHANGED');
    assertCartDesign(str_contains($sources['script'], "return mutate(\n                'delete',") || str_contains($sources['script'], "return mutate(\r\n                'delete',"), 'CART_DELETE_ENDPOINT_CHANGED');
    assertCartDesign(str_contains($sources['script'], "return mutate('delete', '/cart', null, 'Carrito vaciado.')"), 'CART_CLEAR_ENDPOINT_CHANGED');
    assertCartDesign(str_contains($sources['script'], ".then(function () { return apiRequest('get', '/cart'); })"), 'CART_AUTHORITATIVE_GET_REMOVED');
    assertCartDesign(! preg_match('/(?:subtotal|total)\s*[+*\/-]|reduce\s*\(/i', $sources['script']), 'CART_CLIENT_TOTAL_CALCULATION');
    assertCartDesign(str_contains($sources['script'], 'money(item.unit_price_snapshot)') && str_contains($sources['script'], 'money(item.subtotal)') && str_contains($sources['script'], 'money(payload.total)'), 'CART_PRICE_SNAPSHOT_CHANGED');
    assertCartDesign(str_contains($sources['script'], 'headers[cart.sessionHeader] = cart.sessionId') && str_contains($sources['script'], '!(config.currentUser && config.currentUser.loggedIn)'), 'CART_AUTH_PRECEDENCE_CHANGED');
    assertCartDesign(str_contains($sources['script'], 'item.sector_compatible === false') && str_contains($sources['script'], 'va-cart-sector-warning'), 'CART_SECTOR_WARNING_REMOVED');
    assertCartDesign(str_contains($sources['view'], 'href="<?php echo esc_url($catalogUrl); ?>" data-va-cart-continue-shopping'), 'CART_CATALOG_LINK_CHANGED');
    assertCartDesign(str_contains($sources['view'], "if (\$checkoutUrl !== '')") && str_contains($sources['view'], 'data-va-cart-checkout-unavailable'), 'CART_CHECKOUT_LINK_CHANGED');
    assertCartDesign(! preg_match('/prestador|service_provider|whatsapp|contact_email|tel[eÃ©]fono/i', $sources['view'] . $sources['script']), 'CART_PROVIDER_DISCLOSURE');
    assertCartDesign(! preg_match('/Temporada|m[aÃ¡]s vendidos|promoci[oÃ³]n temporal|barra inferior|buscador unificado/i', $sources['view'] . $sources['script']), 'CART_FUTURE_FEATURE');
}

function cartDesignMutate(string $source, string $search, string $replace): string
{
    assertCartDesign(substr_count($source, $search) === 1, "CART_MUTATION_NOT_UNITARY: {$search}");
    return str_replace($search, $replace, $source);
}

/** @param array<string, string> $sources @return array{string,string,string} */
function expectCartDesignRejection(array $sources, string $expected, string $label): array
{
    try {
        validateCartDesign($sources);
    } catch (RuntimeException $exception) {
        assertCartDesign($exception->getMessage() === $expected, "CART_WRONG_DIAGNOSTIC: {$label}; expected={$expected}; obtained={$exception->getMessage()}");
        return [$label, $expected, $exception->getMessage()];
    }
    throw new RuntimeException("CART_ADVERSARIAL_ACCEPTED: {$label}");
}

/** @param list<string> $paths */
function validateCartDesignScope(array $paths): void
{
    sort($paths, SORT_STRING);
    $allowed = [
        'app/Modules/Frontend/Views/cart.php',
        'assets/frontend/js/veciahorra-cart.js',
        'tests/manual/cart-design-system-browser-test.py',
        'tests/manual/frontend-cart-design-system-test.php',
    ];
    sort($allowed, SORT_STRING);
    assertCartDesign($paths === $allowed, 'CART_OUT_OF_SCOPE_FILE');
}

function assertGitFileEquals(string $root, string $commit, string $path, string $diagnostic): void
{
    $expected = trim((string) shell_exec('git rev-parse ' . escapeshellarg($commit . ':' . $path)));
    $actual = trim((string) shell_exec('git hash-object ' . escapeshellarg($root . '/' . $path)));
    assertCartDesign($expected !== '' && hash_equals($expected, $actual), $diagnostic);
}

$root = dirname(__DIR__, 2);
$sources = cartDesignSources($root);
validateCartDesign($sources);

$cases = [
    ['view', 'veciahorra-frontend va-design-system va-public-cart', 'va-public-cart', 'CART_DESIGN_ROOT_MISSING', 'raiz ausente'],
    ['layout', 'class="veciahorra-frontend"', 'class="veciahorra-frontend va-design-system"', 'CART_ROOT_IN_LAYOUT', 'raiz en layout'],
    ['checkout', 'class="va-checkout"', 'class="va-checkout va-design-system"', 'CART_ROOT_IN_CHECKOUT', 'raiz en checkout'],
    ['view', ' data-va-cart-loading', '', 'CART_MOUNT_REMOVED', 'mount loading ausente'],
    ['view', ' aria-labelledby="<?php echo esc_attr($titleId); ?>"', '', 'CART_ARIA_LABELLEDBY_REMOVED', 'labelledby ausente'],
    ['view', '<h1 id="<?php echo esc_attr($titleId); ?>">', '<h1>', 'CART_HEADING_ID_REMOVED', 'heading id ausente'],
    ['view', 'va-section-heading', 'va-heading', 'CART_HEADING_COMPONENT_MISSING', 'heading componente ausente'],
    ['view', 'va-public-cart__content va-card', 'va-public-cart__content', 'CART_CARD_COMPONENT_MISSING', 'card ausente'],
    ['view', 'va-button va-button--primary" type="button" data-va-cart-retry', 'va-button" type="button" data-va-cart-retry', 'CART_RETRY_MAPPING', 'retry no primario'],
    ['view', 'va-button va-button--primary" href="<?php echo esc_url($checkoutUrl); ?>" data-va-cart-checkout', 'va-button" href="<?php echo esc_url($checkoutUrl); ?>" data-va-cart-checkout', 'CART_CHECKOUT_MAPPING', 'checkout no primario'],
    ['view', '<table class="va-cart-table">', '<div class="va-cart-table">', 'CART_TABLE_SEMANTICS_REMOVED', 'tabla eliminada'],
    ['view', 'aria-live="polite" aria-atomic="true" data-va-cart-status', 'data-va-cart-status', 'CART_LIVE_REGION_REMOVED', 'live region eliminada'],
    ['script', "decrease.setAttribute('aria-label'", "decrease.setAttribute('title'", 'CART_QUANTITY_LABEL_REMOVED', 'label cantidad eliminado'],
    ['script', "return apiRequest('get', '/cart').then(render).catch(showError);", "return apiRequest('get', '/basket').then(render).catch(showError);", 'CART_GET_ENDPOINT_CHANGED', 'GET alterado'],
    ['script', '{ quantity: quantity }', '{ quantity: quantity, inventory_id: item.inventory_id }', 'CART_PATCH_PAYLOAD_CHANGED', 'payload PATCH ampliado'],
    ['script', "return mutate('delete', '/cart', null, 'Carrito vaciado.')", "return mutate('delete', '/cart/all', null, 'Carrito vaciado.')", 'CART_CLEAR_ENDPOINT_CHANGED', 'clear alterado'],
    ['script', ".then(function () { return apiRequest('get', '/cart'); })", '.then(function () { return payload; })', 'CART_AUTHORITATIVE_GET_REMOVED', 'GET autoritativo eliminado'],
    ['script', 'money(item.unit_price_snapshot)', 'money(item.price)', 'CART_PRICE_SNAPSHOT_CHANGED', 'snapshot sustituido'],
    ['script', 'money(payload.total)', 'money(payload.total + 1)', 'CART_CLIENT_TOTAL_CALCULATION', 'total calculado'],
    ['script', 'headers[cart.sessionHeader] = cart.sessionId', "headers['X-Test'] = 'fixture'", 'CART_AUTH_PRECEDENCE_CHANGED', 'sesion inventada'],
    ['script', '!(config.currentUser && config.currentUser.loggedIn)', 'true', 'CART_AUTH_PRECEDENCE_CHANGED', 'precedencia auth eliminada'],
    ['script', 'item.sector_compatible === false', 'false', 'CART_SECTOR_WARNING_REMOVED', 'warning sectorial eliminado'],
    ['view', 'href="<?php echo esc_url($catalogUrl); ?>" data-va-cart-continue-shopping', 'href="#" data-va-cart-continue-shopping', 'CART_CATALOG_LINK_CHANGED', 'catalogo alterado'],
    ['view', "if (\$checkoutUrl !== '')", 'if (true)', 'CART_CHECKOUT_LINK_CHANGED', 'checkout forzado'],
    ['view', "esc_html_e('Compra', 'veciahorra')", "esc_html_e('WhatsApp del prestador', 'veciahorra')", 'CART_PROVIDER_DISCLOSURE', 'prestador revelado'],
    ['view', "esc_html_e('Compra', 'veciahorra')", "esc_html_e('Temporada', 'veciahorra')", 'CART_FUTURE_FEATURE', 'funcion futura'],
    ['design_css', 'min-width: 2.75rem;', 'min-width: 2rem;', 'CART_TOUCH_TARGET_REDUCED', 'target reducido'],
];
$adversarials = [];
foreach ($cases as [$file, $search, $replace, $diagnostic, $label]) {
    $candidate = $sources;
    $candidate[$file] = cartDesignMutate($candidate[$file], $search, $replace);
    $adversarials[] = expectCartDesignRejection($candidate, $diagnostic, $label);
}
$adversarials[] = (static function (): array {
    try {
        validateCartDesignScope([
            'app/Modules/Frontend/Views/cart.php',
            'assets/frontend/js/veciahorra-cart.js',
            'tests/manual/cart-design-system-browser-test.py',
            'tests/manual/frontend-cart-design-system-test.php',
            'assets/frontend/css/veciahorra-frontend.css',
        ]);
    } catch (RuntimeException $exception) {
        assertCartDesign($exception->getMessage() === 'CART_OUT_OF_SCOPE_FILE', 'CART_WRONG_DIAGNOSTIC: archivo fuera de alcance');
        return ['archivo fuera de alcance', 'CART_OUT_OF_SCOPE_FILE', $exception->getMessage()];
    }
    throw new RuntimeException('CART_ADVERSARIAL_ACCEPTED: archivo fuera de alcance');
})();
assertCartDesign(count($adversarials) === 28, 'CART_ADVERSARIAL_COUNT');

$markup = do_shortcode('[veciahorra_cart]');
assertCartDesign(str_contains($markup, 'class="veciahorra-frontend va-design-system va-public-cart" data-va-cart'), 'CART_RUNTIME_ROOT');
assertCartDesign(preg_match('/<section[^>]+aria-labelledby="([^"]+)"[^>]*>.*?<h1 id="\1"/s', $markup) === 1, 'CART_RUNTIME_LABEL');
global $wp_scripts;
assertCartDesign(wp_style_is(FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE, 'enqueued') && wp_style_is(FrontendAssets::STYLE_HANDLE, 'enqueued') && wp_script_is(FrontendAssets::SCRIPT_HANDLE, 'enqueued') && wp_script_is(FrontendAssets::CART_SCRIPT_HANDLE, 'enqueued'), 'CART_RUNTIME_ASSETS');
assertCartDesign(count(array_keys($wp_scripts->queue, FrontendAssets::CART_SCRIPT_HANDLE, true)) === 1, 'CART_RUNTIME_IDEMPOTENCE');

foreach ([
    'app/Modules/Frontend/Assets/FrontendAssets.php',
    'app/Modules/Frontend/Controller/FrontendController.php',
    'app/Modules/Frontend/Views/layout.php',
    'assets/frontend/css/veciahorra-design-system.css',
    'tests/manual/frontend-design-system-test.php',
] as $path) {
    assertGitFileEquals($root, 'a7b11b05c1fecd43a23e81b5f4c7bc3ec488d3b0', $path, 'CART_PHASE1_CHANGED');
}
foreach (['app/Modules/Frontend/Views/catalog.php', 'assets/frontend/js/veciahorra-catalog.js', 'assets/frontend/css/veciahorra-frontend.css', 'tests/manual/frontend-catalog-design-system-test.php', 'tests/manual/catalog-filter-alignment-browser-test.py'] as $path) {
    assertGitFileEquals($root, '4c838c806ccd83ff7c5a7095ce0069c0787c7a22', $path, 'CART_PHASE2_CHANGED');
}
foreach (['app/Modules/Frontend/Views/product-detail.php', 'assets/frontend/js/veciahorra-product-offers.js', 'tests/manual/frontend-product-detail-design-system-test.php', 'tests/manual/product-detail-design-system-browser-test.py'] as $path) {
    assertGitFileEquals($root, CART_PHASE4_BASELINE, $path, 'CART_PHASE3_CHANGED');
}
foreach (['app/Modules/Cart/Routes/CartRoutes.php', 'app/Modules/Cart/Controller/CartController.php', 'app/Modules/Cart/Service/CartService.php', 'app/Modules/Cart/Repository/CartRepository.php', 'app/Modules/Cart/Requests/CartItemCreateRequest.php', 'app/Modules/Cart/Requests/CartItemQuantityRequest.php', 'app/Modules/Frontend/Support/CartSession.php', 'app/Modules/Catalog/Service/CatalogService.php'] as $path) {
    assertGitFileEquals($root, CART_PHASE4_BASELINE, $path, 'CART_BACKEND_CHANGED');
}

$untracked = array_values(array_filter(preg_split('/\R/', trim((string) shell_exec('git ls-files --others --exclude-standard'))) ?: []));
assertCartDesign(count($untracked) === 519 && hash('sha256', implode("\n", $untracked)) === '15a45f3aa19cacb8be80b0963476671e388e75501ff5088f839c385bf1d1433d', 'CART_ENVIRONMENT');
$phaseDiff = array_values(array_filter(preg_split('/\R/', trim((string) shell_exec('git diff --name-only ' . CART_PHASE4_BASELINE))) ?: []));
validateCartDesignScope(array_values(array_unique($phaseDiff)));

$artifactFiles = $artifactDirs = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/artifacts', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($iterator as $entry) {
    $entry->isDir() ? $artifactDirs[] = $entry : $artifactFiles[] = $entry;
}
assertCartDesign(count($artifactFiles) === 513 && count($artifactDirs) === 309 && array_sum(array_map(static fn (SplFileInfo $file): int => $file->getSize(), $artifactFiles)) === 28537157, 'CART_ARTIFACTS');

foreach ($adversarials as [$label, $expected, $obtained]) {
    printf("ADVERSARIAL label=%s expected=%s obtained=%s\n", $label, $expected, $obtained);
}
printf("PASS frontend-cart-design-system-test adversarials=%d root=same-node runtime=pass\n", count($adversarials));
