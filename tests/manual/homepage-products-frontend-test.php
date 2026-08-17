<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'module' => $root . '/app/Modules/Frontend/FrontendModule.php',
    'assets' => $root . '/app/Modules/Frontend/Assets/FrontendAssets.php',
    'component' => $root . '/app/Modules/Frontend/Components/HomepageProducts.php',
    'view' => $root . '/app/Modules/Frontend/Views/homepage-products.php',
    'script' => $root . '/assets/frontend/js/veciahorra-homepage-products.js',
    'style' => $root . '/assets/frontend/css/homepage-products.css',
];

/** @param mixed $condition */
function assertHomepageProducts($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = [];
foreach ($files as $name => $path) {
    $contents = file_get_contents($path);
    assertHomepageProducts(is_string($contents), strtoupper($name) . '_UNREADABLE');
    $source[$name] = $contents;
}

assertHomepageProducts(
    substr_count($source['module'], 'HomepageProducts::SHORTCODE') === 1,
    'SHORTCODE_REGISTRATION_COUNT'
);
assertHomepageProducts(
    str_contains($source['component'], "public const SHORTCODE = 'veciahorra_homepage_products';"),
    'SHORTCODE_NAME'
);
assertHomepageProducts(
    str_contains($source['component'], 'private const LIMIT = 6;'),
    'LIMIT_NOT_CLOSED'
);
assertHomepageProducts(
    !str_contains($source['component'], 'sector_id') && !str_contains($source['script'], 'sector_id'),
    'CLIENT_SECTOR_FORBIDDEN'
);
assertHomepageProducts(
    str_contains($source['component'], '$this->assets->enqueueHomepageProducts();'),
    'RENDER_ENQUEUE_MISSING'
);
assertHomepageProducts(
    substr_count($source['assets'], 'wp_enqueue_script(self::HOMEPAGE_PRODUCTS_SCRIPT_HANDLE)') === 1
        && substr_count($source['assets'], 'wp_enqueue_style(self::HOMEPAGE_PRODUCTS_STYLE_HANDLE)') === 1,
    'ASSET_SCOPE_INVALID'
);
assertHomepageProducts(
    str_contains($source['assets'], '[self::SCRIPT_HANDLE, self::PRODUCT_CARD_SCRIPT_HANDLE]'),
    'RENDERER_DEPENDENCY_MISSING'
);

$viewChecks = [
    'data-va-home-products' => 'DOM_ROOT',
    'aria-labelledby="<?php echo esc_attr($titleId); ?>"' => 'ARIA_LABEL',
    'Productos cerca de ti' => 'TITLE',
    'Descubre productos disponibles en minimarkets de tu sector.' => 'INTRO',
    'data-va-home-products-status' => 'STATUS',
    'aria-live="polite"' => 'POLITE_LIVE_REGION',
    'data-va-home-products-grid' => 'GRID',
    'Explorar catálogo' => 'CATALOG_CTA',
    'esc_url($catalogUrl)' => 'URL_ESCAPE',
    'esc_attr($titleId)' => 'ATTRIBUTE_ESCAPE',
];
foreach ($viewChecks as $needle => $message) {
    assertHomepageProducts(str_contains($source['view'], $needle), $message);
}
assertHomepageProducts(substr_count($source['view'], '<section') === 1, 'ROOT_COUNT');
assertHomepageProducts(!str_contains($source['view'], 'elementor-'), 'ELEMENTOR_COUPLING');

$scriptChecks = [
    "var endpoint = '/catalog/homepage-products';" => 'ENDPOINT',
    "headingTag: 'h3'" => 'CARD_HEADING',
    "modifierClass: 'va-catalog-card--homepage'" => 'CARD_MODIFIER',
    'window.VeciAhorraProductCard' => 'SHARED_RENDERER',
    'new window.AbortController()' => 'ABORT_CONTROLLER',
    'current !== sequence' => 'STALE_SEQUENCE',
    "'Cargando productos…'" => 'LOADING_STATE',
    "'Selecciona un sector para ver productos disponibles.'" => 'NO_SECTOR_STATE',
    "'No hay productos disponibles en tu sector.'" => 'EMPTY_STATE',
    "'No fue posible cargar los productos.'" => 'ERROR_STATE',
    "'invalid-response'" => 'INVALID_RESPONSE_STATE',
    "'Reintentar'" => 'RETRY',
    "action.textContent = 'Seleccionar sector'" => 'SECTOR_ACTION_LABEL',
    "document.createElement('a')" => 'SECTOR_ACTION_LINK',
    'products.length > 6' => 'RESPONSE_LIMIT',
    'ids[product.id]' => 'DUPLICATE_GUARD',
    "url.searchParams.set('product_id'" => 'PRODUCT_URL',
];
foreach ($scriptChecks as $needle => $message) {
    assertHomepageProducts(str_contains($source['script'], $needle), $message);
}
assertHomepageProducts(substr_count($source['script'], 'config.api.get(endpoint') === 1, 'REQUEST_COUNT');
foreach (['/catalog/categories', '/catalog/products', 'inventory_id', 'innerHTML'] as $forbidden) {
    assertHomepageProducts(!str_contains($source['script'], $forbidden), 'FORBIDDEN_' . $forbidden);
}
assertHomepageProducts(!str_contains($source['script'], '[data-va-sector-select]'), 'SECTOR_SELECTOR_DEPENDENCY');
assertHomepageProducts(!str_contains($source['script'], 'dispatchEvent'), 'SECTOR_EVENT_DEPENDENCY');

assertHomepageProducts(str_contains($source['style'], '@media (min-width: 768px)'), 'TABLET_BREAKPOINT');
assertHomepageProducts(str_contains($source['style'], '@media (min-width: 992px)'), 'DESKTOP_BREAKPOINT');
assertHomepageProducts(str_contains($source['style'], 'repeat(3, minmax(0, 1fr))'), 'DESKTOP_COLUMNS');
assertHomepageProducts(str_contains($source['style'], 'repeat(2, minmax(0, 1fr))'), 'TABLET_COLUMNS');
assertHomepageProducts(str_contains($source['style'], 'outline: 2px'), 'FOCUS_CONTRACT');
assertHomepageProducts(str_contains($source['style'], 'prefers-reduced-motion: reduce'), 'REDUCED_MOTION');
assertHomepageProducts(!str_contains($source['style'], '!important'), 'IMPORTANT_FORBIDDEN');

echo json_encode([
    'shortcode' => 'PASS',
    'assets' => 'PASS',
    'dom' => 'PASS',
    'states' => 'PASS',
    'request_contract' => 'PASS',
    'renderer' => 'PASS',
    'accessibility' => 'PASS',
    'responsive_css' => 'PASS',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
