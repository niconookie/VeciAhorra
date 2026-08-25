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
        'product_card' => 'assets/frontend/js/veciahorra-product-card.js',
        'legacy_css' => 'assets/frontend/css/veciahorra-frontend.css',
        'layout' => 'app/Modules/Frontend/Views/layout.php',
        'product' => 'app/Modules/Frontend/Views/product-detail.php',
        'cart' => 'app/Modules/Frontend/Views/cart.php',
        'checkout' => 'app/Modules/Frontend/Views/checkout.php',
        'assets' => 'app/Modules/Frontend/Assets/FrontendAssets.php',
        'controller' => 'app/Modules/Frontend/Controller/FrontendController.php',
        'design_css' => 'assets/frontend/css/veciahorra-design-system.css',
    ];
    $sources = [];
    foreach ($paths as $name => $path) {
        $contents = file_get_contents($root . '/' . $path);
        assertCatalogDesign(is_string($contents) && $contents !== '', "CATALOG_SOURCE: {$path}.");
        $sources[$name] = $contents;
    }
    return $sources;
}

function catalogEffectiveMarkup(string $source): string
{
    $markup = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_INLINE_HTML) {
            $markup .= $token[1];
        }
    }
    return $markup;
}

/** @return array{document: DOMDocument, xpath: DOMXPath} */
function catalogMarkupDom(string $source): array
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadHTML(
        '<!doctype html><html><body><div data-va-test-wrapper>' . catalogEffectiveMarkup($source) . '</div></body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    assertCatalogDesign($loaded, 'CATALOG_MARKUP_PARSE_FAILED');
    return ['document' => $document, 'xpath' => new DOMXPath($document)];
}

function catalogClassExpression(string $class): string
{
    return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
}

function validateCatalogRootContract(string $source): void
{
    $dom = catalogMarkupDom($source);
    $xpath = $dom['xpath'];
    $class = catalogClassExpression('va-catalog');
    $classNodes = $xpath->query("//*[$class]");
    $attributeNodes = $xpath->query('//*[@data-va-catalog]');
    $rootNodes = $xpath->query("//*[$class and @data-va-catalog]");
    assertCatalogDesign($classNodes !== false && $classNodes->length === 1, 'CATALOG_CLASS_IDENTITY_COUNT');
    assertCatalogDesign($attributeNodes !== false && $attributeNodes->length === 1, 'CATALOG_ATTRIBUTE_IDENTITY_COUNT');
    assertCatalogDesign($rootNodes !== false && $rootNodes->length === 1, 'CATALOG_ROOT_SAME_NODE');
    $root = $rootNodes->item(0);
    assertCatalogDesign($root instanceof DOMElement, 'CATALOG_ROOT_MISSING');
    foreach (['veciahorra-frontend', 'va-design-system', 'va-catalog'] as $requiredClass) {
        assertCatalogDesign(preg_match('/(?:^|\s)' . preg_quote($requiredClass, '/') . '(?:\s|$)/', $root->getAttribute('class')) === 1, 'CATALOG_ROOT_CLASS_SET');
    }
    $nested = $xpath->query("//*[$class and @data-va-catalog]//*[$class and @data-va-catalog]");
    assertCatalogDesign($nested !== false && $nested->length === 0, 'CATALOG_ROOT_NESTED');
}

function validateNonCatalogSurface(string $source, string $surface): void
{
    $dom = catalogMarkupDom($source);
    $xpath = $dom['xpath'];
    $class = catalogClassExpression('va-catalog');
    $identity = $xpath->query("//*[$class or @data-va-catalog]");
    assertCatalogDesign($identity !== false && $identity->length === 0, 'CATALOG_IDENTITY_IN_' . strtoupper($surface));
}

function validateLayoutSectorContract(string $source): void
{
    $dom = catalogMarkupDom($source);
    $xpath = $dom['xpath'];
    $frontend = catalogClassExpression('veciahorra-frontend');
    $designSystem = catalogClassExpression('va-design-system');
    $sectors = $xpath->query("//*[@data-va-sector-selector and $frontend and $designSystem]");
    assertCatalogDesign($sectors !== false && $sectors->length === 1, 'CATALOG_SECTORIZATION_CHANGED');
}

/** @param array<string, string> $sources */
function validateCatalogDesign(array $sources): void
{
    validateCatalogRootContract($sources['view']);
    foreach (['layout', 'product', 'cart', 'checkout'] as $surface) {
        validateNonCatalogSurface($sources[$surface], $surface);
    }
    validateLayoutSectorContract($sources['layout']);

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

    assertCatalogDesign(str_contains($sources['product_card'], "el('article', 'va-card va-catalog-card')"), 'CATALOG_CARD_COMPONENT_MISSING');
    assertCatalogDesign(str_contains($sources['script'], 'window.VeciAhorraProductCard') && str_contains($sources['script'], 'renderer.render(product'), 'CATALOG_SHARED_RENDERER_REMOVED');
    assertCatalogDesign(str_contains($sources['view'], 'class="va-button va-button--primary va-catalog__apply" type="submit"'), 'CATALOG_PRIMARY_MAPPING');
    assertCatalogDesign(str_contains($sources['view'], 'class="va-button va-button--secondary" type="button" data-va-catalog-reset'), 'CATALOG_SECONDARY_MAPPING');
    assertCatalogDesign(str_contains($sources['product_card'], "el('a', 'va-button va-button--primary va-catalog-card__action', 'Ver producto')") && str_contains($sources['product_card'], "el('a', 'va-catalog-card__action', 'Ver opciones')") && str_contains($sources['product_card'], 'link.href = url;'), 'CATALOG_DETAIL_LINK_REMOVED');
    assertCatalogDesign(str_contains($sources['script'], "Number(product.eligible_offers) !== 1") && str_contains($sources['script'], "product.single_offer_token") && str_contains($sources['script'], "config.api.post('/cart/items'") && str_contains($sources['script'], 'offer_token: offerToken'), 'CATALOG_DIRECT_CART_GUARD');
    assertCatalogDesign(str_contains($sources['script'], "url.searchParams.set('product_id', id)"), 'CATALOG_PRODUCT_ID_REMOVED');
    assertCatalogDesign(str_contains($sources['view'], '<label for="<?php echo esc_attr($instanceId . \'-search\'); ?>">'), 'CATALOG_LABEL_REMOVED');
    assertCatalogDesign(str_contains($sources['view'], 'role="alert" data-va-catalog-error'), 'CATALOG_ALERT_ROLE_REMOVED');
    assertCatalogDesign(str_contains($sources['view'], 'role="status" aria-live="polite" aria-atomic="true" data-va-catalog-status'), 'CATALOG_LIVE_REGION_REMOVED');
    assertCatalogDesign(str_contains($sources['design_css'], '.va-button:focus-visible,') && str_contains($sources['design_css'], ':is(input, select, textarea):focus-visible'), 'CATALOG_FOCUS_REMOVED');
    assertCatalogDesign(str_contains($sources['design_css'], 'min-width: 2.75rem;') && str_contains($sources['design_css'], 'min-height: 2.75rem;'), 'CATALOG_TOUCH_TARGET_REDUCED');
    assertCatalogDesign(! preg_match('/Temporada|m[aá]s vendidos|promoci[oó]n temporal|oferta temporal/i', $sources['view'] . $sources['script'] . $bridge), 'CATALOG_FUTURE_FEATURE');
    assertCatalogDesign(! preg_match('/prestador|service_provider|whatsapp|contact_email|tel[eé]fono del prestador/i', $sources['view'] . $sources['script'] . $bridge), 'CATALOG_PROVIDER_DISCLOSURE');
    assertCatalogDesign(str_contains($sources['script'], "'/catalog/products?'") && ! str_contains($sources['script'], "'/catalog/categories'"), 'CATALOG_ENDPOINT_CHANGED');

    $immutable = [
        'controller' => '79f958580e0d9905b16b0dbf4580a2fa1203b4ef48cc193355adc02b6e84e686',
        'design_css' => '0a95b693528efd2ba84198de3e0535726b99a4ca4032c16746530f1585f10635',
    ];
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

$root = dirname(__DIR__, 2);
$sources = catalogDesignSources($root);
validateCatalogDesign($sources);

$adversarials = [];
$cases = [
    ['layout', 'class="veciahorra-frontend va-design-system"', 'class="veciahorra-frontend va-design-system va-catalog"', 'CATALOG_IDENTITY_IN_LAYOUT', 'clase catalogo en selector'],
    ['layout', 'data-va-sector-selector', 'data-va-sector-selector data-va-catalog', 'CATALOG_IDENTITY_IN_LAYOUT', 'atributo catalogo en selector'],
    ['layout', '    <main id=', '    <section class="veciahorra-frontend va-design-system va-catalog" data-va-catalog></section>\n    <main id=', 'CATALOG_IDENTITY_IN_LAYOUT', 'segunda raiz en layout'],
    ['product', 'data-va-product-detail', 'data-va-product-detail data-va-catalog', 'CATALOG_IDENTITY_IN_PRODUCT', 'identidad catalogo en ficha'],
    ['cart', 'data-va-cart aria-labelledby', 'data-va-cart data-va-catalog aria-labelledby', 'CATALOG_IDENTITY_IN_CART', 'identidad catalogo en carrito'],
    ['checkout', 'data-va-checkout aria-labelledby', 'data-va-checkout data-va-catalog aria-labelledby', 'CATALOG_IDENTITY_IN_CHECKOUT', 'identidad catalogo en checkout'],
    ['view', ' va-catalog" data-va-catalog', '" data-va-catalog', 'CATALOG_CLASS_IDENTITY_COUNT', 'clase raiz eliminada'],
    ['view', '" data-va-catalog data-product-urls', '" data-product-urls', 'CATALOG_ATTRIBUTE_IDENTITY_COUNT', 'atributo raiz eliminado'],
    ['view', '<header class="va-catalog__heading', '<div data-va-catalog></div>\n    <header class="va-catalog__heading', 'CATALOG_ATTRIBUTE_IDENTITY_COUNT', 'clase y atributo separados'],
    ['view', '<header class="va-catalog__heading', '<section class="veciahorra-frontend va-design-system va-catalog" data-va-catalog></section>\n    <header class="va-catalog__heading', 'CATALOG_CLASS_IDENTITY_COUNT', 'raiz duplicada anidada'],
    ['legacy_css', '.veciahorra-frontend.va-design-system.va-catalog .va-catalog__filters .va-button--secondary', '.veciahorra-frontend.va-design-system .va-catalog .va-catalog__filters .va-button--secondary', 'CATALOG_WRONG_DESCENDANT_SELECTOR', 'selector descendiente'],
    ['legacy_css', '/* Phase 2 public catalog design-system bridge. */', "/* Phase 2 public catalog design-system bridge. */\nbody { color: red; }", 'CATALOG_GLOBAL_SELECTOR', 'selector global'],
    ['legacy_css', '/* Phase 2 public catalog design-system bridge. */', "/* Phase 2 public catalog design-system bridge. */\n.veciahorra-frontend.va-design-system.va-catalog + body { color: red; }", 'CATALOG_SIBLING_COMBINATOR', 'hermano externo'],
    ['design_css', '--va-color-primary:', '--va-color-primary: /* changed */', 'CATALOG_PHASE1_ASSET_CHANGED', 'asset Fase 1'],
    ['product_card', "el('article', 'va-card va-catalog-card')", "el('article', 'va-catalog-card')", 'CATALOG_CARD_COMPONENT_MISSING', 'tarjeta sin componente'],
    ['view', 'va-button va-button--primary va-catalog__apply" type="submit"', 'va-button va-catalog__apply" type="submit"', 'CATALOG_PRIMARY_MAPPING', 'aplicar sin primary'],
    ['view', 'va-button va-button--secondary" type="button" data-va-catalog-reset', 'va-button va-button--primary" type="button" data-va-catalog-reset', 'CATALOG_SECONDARY_MAPPING', 'restablecer primary'],
    ['product_card', "el('a', 'va-catalog-card__action', 'Ver opciones')", "el('span', 'va-catalog-card__action', 'Ver opciones')", 'CATALOG_DETAIL_LINK_REMOVED', 'enlace eliminado'],
    ['script', "Number(product.eligible_offers) !== 1", "Number(product.eligible_offers) !== 2", 'CATALOG_DIRECT_CART_GUARD', 'guardia de oferta unica'],
    ['script', "url.searchParams.set('product_id', id)", "url.searchParams.set('item', id)", 'CATALOG_PRODUCT_ID_REMOVED', 'product id eliminado'],
    ['view', '<label for="<?php echo esc_attr($instanceId . \'-search\'); ?>">', '<span>', 'CATALOG_LABEL_REMOVED', 'label eliminado'],
    ['view', 'role="alert" data-va-catalog-error', 'data-va-catalog-error', 'CATALOG_ALERT_ROLE_REMOVED', 'alert eliminado'],
    ['view', 'role="status" aria-live="polite" aria-atomic="true" data-va-catalog-status', 'data-va-catalog-status', 'CATALOG_LIVE_REGION_REMOVED', 'live region eliminada'],
    ['design_css', '.va-button:focus-visible,', '.va-button:focus-within,', 'CATALOG_FOCUS_REMOVED', 'focus eliminado'],
    ['design_css', 'min-width: 2.75rem;', 'min-width: 2rem;', 'CATALOG_TOUCH_TARGET_REDUCED', 'touch target reducido'],
    ['view', 'Productos cerca de ti', 'Temporada', 'CATALOG_FUTURE_FEATURE', 'funcion futura'],
    ['view', 'Productos cerca de ti', 'WhatsApp del prestador', 'CATALOG_PROVIDER_DISCLOSURE', 'prestador revelado'],
    ['script', "'/catalog/products?'", "'/market/products?'", 'CATALOG_ENDPOINT_CHANGED', 'endpoint alterado'],
    ['layout', 'data-va-sector-selector', 'data-va-zone-selector', 'CATALOG_SECTORIZATION_CHANGED', 'sectorizacion alterada'],
    ['layout', 'class="veciahorra-frontend va-design-system"', 'class="veciahorra-frontend"', 'CATALOG_SECTORIZATION_CHANGED', 'design system sectorial eliminado'],
];
foreach ($cases as [$file, $search, $replace, $diagnostic, $label]) {
    $candidate = $sources;
    $candidate[$file] = catalogMutateOnce($candidate[$file], $search, $replace);
    $adversarials[] = expectCatalogRejection($candidate, $diagnostic, $label);
}
$inertCases = [
    ['layout', "<?php /* va-catalog data-va-catalog */ ?>\n", 'comentario PHP'],
    ['layout', "<!-- <section class=\"va-catalog\" data-va-catalog></section> -->\n", 'comentario HTML'],
    ['layout', "<?php \$inertCatalogIdentity = 'va-catalog data-va-catalog'; ?>\n", 'string PHP inerte'],
    ['layout', "Texto fixture: va-catalog data-va-catalog\n", 'texto de fixture'],
    ['layout', "Mensaje descriptivo: la identidad va-catalog usa data-va-catalog.\n", 'mensaje descriptivo'],
];
$inertAccepted = [];
foreach ($inertCases as [$file, $prefix, $label]) {
    $candidate = $sources;
    $candidate[$file] = $prefix . $candidate[$file];
    validateCatalogDesign($candidate);
    $inertAccepted[] = $label;
}
assertCatalogDesign(count($adversarials) === 30, 'CATALOG_ADVERSARIAL_COUNT');
assertCatalogDesign(count($inertAccepted) === 5, 'CATALOG_INERT_COUNT');

$catalogMarkup = do_shortcode('[veciahorra_frontend]');
$productMarkup = do_shortcode('[veciahorra_frontend product_id="1"]');
assertCatalogDesign(str_contains($catalogMarkup, 'class="veciahorra-frontend va-design-system va-catalog"') && str_contains($catalogMarkup, 'data-va-catalog'), 'CATALOG_RUNTIME_ROOT');
validateCatalogRootContract($catalogMarkup);
validateNonCatalogSurface($productMarkup, 'runtime_product');
global $wp_styles;
$designPosition = array_search(FrontendAssets::DESIGN_SYSTEM_STYLE_HANDLE, $wp_styles->queue, true);
$legacyPosition = array_search(FrontendAssets::STYLE_HANDLE, $wp_styles->queue, true);
assertCatalogDesign(is_int($designPosition) && is_int($legacyPosition) && $legacyPosition < $designPosition, 'CATALOG_RUNTIME_STYLE_ORDER');

foreach ($adversarials as [$label, $expected, $obtained]) {
    printf("ADVERSARIAL label=%s expected=%s obtained=%s\n", $label, $expected, $obtained);
}
printf("PASS frontend-catalog-design-system-test adversarials=%d inert=%d root=same-node sector=independent runtime=pass\n", count($adversarials), count($inertAccepted));
