<?php

declare(strict_types=1);

use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;

require_once dirname(__DIR__, 5) . '/wp-load.php';

const VA_MARKETPLACE_PRE_A2_AUTHORITY = 'historical_only';
const VA_MARKETPLACE_CURRENT_AUTHORITY = 'A2_SHARED_RENDERER_CONTRACT';

function assertMarketplaceVisibility(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function marketplaceMutateOnce(string $source, string $search, string $replace): string
{
    assertMarketplaceVisibility(substr_count($source, $search) === 1, 'MUTATION_NOT_UNITARY: ' . $search);
    return str_replace($search, $replace, $source);
}

function marketplaceStripJsCommentsAndStrings(string $source): string
{
    return preg_replace_callback(
        '~(?://[^\r\n]*|/\*[\s\S]*?\*/|\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")~',
        static fn (array $match): string => str_starts_with($match[0], '/') ? '' : "''",
        $source
    ) ?? '';
}

/** @param array{assets:string,renderer:string,catalog:string} $sources */
function validateMarketplaceSharedRenderer(array $sources): void
{
    assertMarketplaceVisibility($sources['renderer'] !== '', 'SHARED_RENDERER_ASSET_MISSING');
    assertMarketplaceVisibility(preg_match('/window\.VeciAhorraProductCard\s*=\s*Object\.freeze\s*\(\s*\{\s*render\s*:\s*render\s*}\s*\)/', $sources['renderer']) === 1, 'SHARED_RENDERER_API_MISSING');
    assertMarketplaceVisibility(preg_match('/self::CATALOG_SCRIPT_HANDLE,\s*\$baseUrl\s*\.\s*\'js\/veciahorra-catalog\.js\',\s*\[self::SCRIPT_HANDLE,\s*self::PRODUCT_CARD_SCRIPT_HANDLE]/s', $sources['assets']) === 1, 'CATALOG_DEPENDENCY_MISSING');
    assertMarketplaceVisibility(preg_match('/renderer\s*=\s*window\.VeciAhorraProductCard[\s\S]*?renderer\.render\s*\(\s*product\s*,/', $sources['catalog']) === 1, 'CATALOG_DELEGATION_MISSING');
    assertMarketplaceVisibility(preg_match('/\bfunction\s+card\s*\(/', marketplaceStripJsCommentsAndStrings($sources['catalog'])) !== 1, 'PRIVATE_RENDERER_DUPLICATED');
    assertMarketplaceVisibility(str_contains($sources['renderer'], "'va-catalog-card__price'"), 'PRICE_RENDERING_MISSING');
    assertMarketplaceVisibility(str_contains($sources['renderer'], "'va-catalog-card__availability'"), 'AVAILABILITY_RENDERING_MISSING');
    assertMarketplaceVisibility(str_contains($sources['renderer'], "'va-catalog-card__image-missing'"), 'IMAGE_FALLBACK_MISSING');
    assertMarketplaceVisibility(str_contains($sources['renderer'], 'va-catalog-card__action'), 'PRODUCT_LINK_MISSING');
    assertMarketplaceVisibility(str_contains($sources['renderer'], 'node.textContent = text'), 'TEXT_SECURITY_MISSING');
    assertMarketplaceVisibility(str_contains($sources['renderer'], "parsed.protocol === 'http:' || parsed.protocol === 'https:'"), 'URL_SECURITY_MISSING');
    assertMarketplaceVisibility(! str_contains($sources['renderer'], "parsed.protocol === 'javascript:'"), 'JAVASCRIPT_PROTOCOL_ACCEPTED');
    assertMarketplaceVisibility(str_contains($sources['renderer'], "image.loading = 'lazy'"), 'LAZY_LOADING_MISSING');
    assertMarketplaceVisibility(str_contains($sources['renderer'], 'image.alt = name'), 'IMAGE_ALT_MISSING');
    assertMarketplaceVisibility(str_contains($sources['catalog'], "typeof renderer.render !== 'function'"), 'DEPENDENCY_GUARD_MISSING');
    assertMarketplaceVisibility(! str_contains($sources['renderer'], 'fetch(') && ! str_contains($sources['renderer'], '.api.'), 'RENDERER_REQUEST_ADDED');
}

function marketplaceRemoveTree(string $path): void
{
    if (! is_dir($path)) { return; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($path);
}

/** @return array<string, mixed> */
function marketplaceRunRendererInChrome(string $renderer): array
{
    $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    assertMarketplaceVisibility(is_file($chrome), 'CHROME_NOT_AVAILABLE');
    $token = bin2hex(random_bytes(8));
    $htmlPath = sys_get_temp_dir() . '/va-marketplace-' . $token . '.html';
    $profilePath = sys_get_temp_dir() . '/va-marketplace-profile-' . $token;
    $tests = <<<'JS'
(function () {
    var api = window.VeciAhorraProductCard, checks = [];
    function check(value, name) { if (!value) { throw new Error(name); } checks.push(name); }
    function render(product, options) { var node = api.render(product, options); document.body.appendChild(node); return node; }
    check(api && typeof api.render === 'function' && Object.isFrozen(api), 'exact_api');
    var normal = render({id:7,name:'Café Ñandú',image:'https://example.test/product.jpg',min_price:'1250',available_minimarkets:2},{url:'https://example.test/producto/?product_id=7'});
    check(normal instanceof HTMLElement && normal.matches('.va-catalog-card'), 'HTMLElement_root');
    check(normal.querySelector('h2').textContent === 'Café Ñandú', 'heading_name');
    var image = normal.querySelector('img');
    check(image && image.alt === 'Café Ñandú' && image.loading === 'lazy' && image.decoding === 'async', 'image');
    check(normal.querySelector('.va-catalog-card__price-prefix').textContent === 'Desde' && /1[\.\s]250/.test(normal.querySelector('.va-catalog-card__price-value').textContent), 'price');
    check(normal.querySelector('.va-catalog-card__availability').textContent === 'Disponible en 2 minimarkets', 'availability');
    check(normal.querySelector('.va-catalog-card__action').textContent === 'Ver producto' && normal.querySelector('.va-catalog-card__action').href.includes('product_id=7'), 'link');
    check(!normal.querySelector('button'), 'no_cart_button');
    window.__marketplacePwned = 0;
    var hostile = render({name:'<img src=x onerror="window.__marketplacePwned=1"><script>window.__marketplacePwned=1<\/script> 漢字',image:'javascript:alert(1)',min_price:'not-a-number',available_minimarkets:'bad'},{url:'javascript:alert(1)',headingTag:'script',modifierClass:'safe onclick=evil'});
    check(hostile.querySelector('h2') && hostile.querySelector('h2').textContent.includes('<script>'), 'hostile_text');
    check(!hostile.querySelector('script') && !hostile.querySelector('[onerror]') && window.__marketplacePwned === 0, 'no_html_execution');
    check(hostile.querySelector('.va-catalog-card__image-missing') && hostile.querySelector('.va-catalog-card__unavailable'), 'unsafe_url_fallback');
    check(!hostile.querySelector('.va-catalog-card__price') && !hostile.querySelector('.va-catalog-card__availability'), 'invalid_numbers');
    check(!hostile.classList.contains('onclick=evil'), 'invalid_modifier');
    var absent = render({name:'Sin imagen',image:'',min_price:0,available_minimarkets:1},{url:'%%%'});
    check(absent.querySelector('.va-catalog-card__image-missing') && absent.querySelector('.va-catalog-card__price-value'), 'absent_image_zero_price');
    var negative = render({name:'Negativo seguro',min_price:-1,available_minimarkets:-1},{});
    check(negative.querySelector('.va-catalog-card__price') && !negative.querySelector('.va-catalog-card__availability'), 'negative_invalid_availability');
    document.body.innerHTML = '<pre id="va-result"></pre>';
    document.getElementById('va-result').textContent = JSON.stringify({status:'PASS',checks:checks.length,exceptions:0});
}());
JS;
    try {
        assertMarketplaceVisibility(file_put_contents($htmlPath, '<!doctype html><meta charset="utf-8"><body><script>' . $renderer . '</script><script>' . $tests . '</script></body>') !== false, 'BROWSER_FIXTURE_WRITE_FAILED');
        $command = escapeshellarg($chrome) . ' --headless=new --disable-gpu --no-sandbox --allow-file-access-from-files --virtual-time-budget=2500 --user-data-dir=' . escapeshellarg($profilePath) . ' --dump-dom ' . escapeshellarg('file:///' . str_replace('\\', '/', $htmlPath)) . ' 2>&1';
        $output = (string) shell_exec($command);
        assertMarketplaceVisibility(preg_match('/<pre id="va-result">([^<]+)<\/pre>/', $output, $match) === 1, 'REAL_JS_EXECUTION_FAILED: ' . substr($output, -1000));
        $result = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
        assertMarketplaceVisibility(($result['status'] ?? null) === 'PASS', 'REAL_JS_CONTRACT_FAILED');
        return $result;
    } finally {
        if (is_file($htmlPath)) { unlink($htmlPath); }
        marketplaceRemoveTree($profilePath);
    }
}

function marketplaceRunMissingDependencyInChrome(string $catalog): void
{
    $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    $token = bin2hex(random_bytes(8));
    $htmlPath = sys_get_temp_dir() . '/va-marketplace-missing-' . $token . '.html';
    $profilePath = sys_get_temp_dir() . '/va-marketplace-missing-profile-' . $token;
    $markup = <<<'HTML'
<section data-va-catalog data-product-urls="{}" data-catalog-url="https://example.test/catalog">
<form data-va-catalog-filters><input data-va-catalog-search><select data-va-catalog-category></select><span data-va-catalog-category-status></span><select data-va-catalog-order><option value="name">name</option></select><button data-va-catalog-reset type="button"></button></form>
<div data-va-catalog-loading></div><div data-va-catalog-error hidden><span data-va-catalog-error-message></span><button data-va-catalog-retry></button></div><div data-va-catalog-empty hidden></div><div data-va-catalog-grid hidden></div><div data-va-catalog-status></div>
</section>
HTML;
    $before = <<<'JS'
window.__vaUnhandled = 0;
window.addEventListener('error', function () { window.__vaUnhandled++; });
window.addEventListener('unhandledrejection', function () { window.__vaUnhandled++; });
window.VeciAhorra = {api:{get:function(path){return Promise.resolve(path.includes('categories') ? [] : [{id:7,name:'Producto',min_price:'1000',available_minimarkets:1}]);}}};
JS;
    $after = <<<'JS'
setTimeout(function () {
 var error = document.querySelector('[data-va-catalog-error]');
 var message = document.querySelector('[data-va-catalog-error-message]');
 document.body.innerHTML='<pre id="va-result"></pre>';
 document.getElementById('va-result').textContent=JSON.stringify({status:(!error.hidden && message.textContent==='No fue posible mostrar los productos.' && window.__vaUnhandled===0)?'PASS':'FAIL'});
}, 100);
JS;
    try {
        file_put_contents($htmlPath, '<!doctype html><meta charset="utf-8"><body>' . $markup . '<script>' . $before . '</script><script>' . $catalog . '</script><script>' . $after . '</script></body>');
        $command = escapeshellarg($chrome) . ' --headless=new --disable-gpu --no-sandbox --virtual-time-budget=1000 --user-data-dir=' . escapeshellarg($profilePath) . ' --dump-dom ' . escapeshellarg('file:///' . str_replace('\\', '/', $htmlPath)) . ' 2>&1';
        $output = (string) shell_exec($command);
        assertMarketplaceVisibility(preg_match('/<pre id="va-result">([^<]+)<\/pre>/', $output, $match) === 1, 'DEPENDENCY_MISSING_BROWSER_FAILED');
        $result = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
        assertMarketplaceVisibility(($result['status'] ?? null) === 'PASS', 'DEPENDENCY_MISSING_NOT_CONTROLLED');
    } finally {
        if (is_file($htmlPath)) { unlink($htmlPath); }
        marketplaceRemoveTree($profilePath);
    }
}

$root = dirname(__DIR__, 2);
$paths = ['assets' => $root . '/app/Modules/Frontend/Assets/FrontendAssets.php', 'renderer' => $root . '/assets/frontend/js/veciahorra-product-card.js', 'catalog' => $root . '/assets/frontend/js/veciahorra-catalog.js'];
$sources = [];
foreach ($paths as $name => $path) {
    $source = file_get_contents($path);
    assertMarketplaceVisibility(is_string($source) && $source !== '', strtoupper($name) . '_SOURCE_MISSING');
    $sources[$name] = $source;
}
validateMarketplaceSharedRenderer($sources);

$assets = new FrontendAssets();
$assets->registerAssets();
global $wp_scripts;
$rendererRegistration = $wp_scripts->registered[FrontendAssets::PRODUCT_CARD_SCRIPT_HANDLE] ?? null;
$catalogRegistration = $wp_scripts->registered[FrontendAssets::CATALOG_SCRIPT_HANDLE] ?? null;
assertMarketplaceVisibility($rendererRegistration && str_ends_with($rendererRegistration->src, 'js/veciahorra-product-card.js'), 'RUNTIME_RENDERER_REGISTRATION_MISSING');
assertMarketplaceVisibility($catalogRegistration && in_array(FrontendAssets::PRODUCT_CARD_SCRIPT_HANDLE, $catalogRegistration->deps, true), 'RUNTIME_CATALOG_DEPENDENCY_MISSING');
$browser = marketplaceRunRendererInChrome($sources['renderer']);
marketplaceRunMissingDependencyInChrome($sources['catalog']);

$mutations = [
    ['renderer', $sources['renderer'], '', 'SHARED_RENDERER_ASSET_MISSING'],
    ['renderer', 'window.VeciAhorraProductCard = Object.freeze({ render: render });', '', 'SHARED_RENDERER_API_MISSING'],
    ['renderer', 'render: render', 'card: render', 'SHARED_RENDERER_API_MISSING'],
    ['assets', "'js/veciahorra-catalog.js',\n            [self::SCRIPT_HANDLE, self::PRODUCT_CARD_SCRIPT_HANDLE]", "'js/veciahorra-catalog.js',\n            [self::SCRIPT_HANDLE]", 'CATALOG_DEPENDENCY_MISSING'],
    ['catalog', 'fragment.appendChild(renderer.render(product, {', 'fragment.appendChild(document.createElement("article"), {', 'CATALOG_DELEGATION_MISSING'],
    ['catalog', "    function mount(root) {", "    function card() {}\n\n    function mount(root) {", 'PRIVATE_RENDERER_DUPLICATED'],
    ['renderer', "'va-catalog-card__price'", "'removed-price'", 'PRICE_RENDERING_MISSING'],
    ['renderer', "'va-catalog-card__availability'", "'removed-availability'", 'AVAILABILITY_RENDERING_MISSING'],
    ['renderer', "'va-catalog-card__image-missing'", "'removed-image-fallback'", 'IMAGE_FALLBACK_MISSING'],
    ['renderer', "'va-button va-button--primary va-catalog-card__action'", "'va-button va-button--primary removed-product-action'", 'PRODUCT_LINK_MISSING'],
    ['renderer', 'node.textContent = text', 'node.innerHTML = text', 'TEXT_SECURITY_MISSING'],
    ['renderer', "parsed.protocol === 'http:' || parsed.protocol === 'https:'", "parsed.protocol === 'http:'", 'URL_SECURITY_MISSING'],
    ['renderer', "parsed.protocol === 'http:' || parsed.protocol === 'https:'", "parsed.protocol === 'javascript:'", 'URL_SECURITY_MISSING'],
    ['renderer', "image.loading = 'lazy'", '', 'LAZY_LOADING_MISSING'],
    ['renderer', 'image.alt = name', '', 'IMAGE_ALT_MISSING'],
    ['catalog', "typeof renderer.render !== 'function'", "typeof renderer.render === 'function'", 'DEPENDENCY_GUARD_MISSING'],
];
$rejected = 0;
foreach ($mutations as [$file, $search, $replace, $expected]) {
    $candidate = $sources;
    $candidate[$file] = $search === $sources[$file] ? $replace : marketplaceMutateOnce($candidate[$file], $search, $replace);
    try { validateMarketplaceSharedRenderer($candidate); } catch (RuntimeException $exception) {
        assertMarketplaceVisibility(str_contains($exception->getMessage(), $expected), 'WRONG_ADVERSARIAL_DIAGNOSTIC');
        $rejected++;
        continue;
    }
    throw new RuntimeException('ADVERSARIAL_ACCEPTED: ' . $expected);
}

$inert = ["\n// function card() {} va-catalog-card__price\n", "\n/* window.VeciAhorraProductCard fake fixture */\n", "\nvar fixtureMessage = 'function card() {}';\n", "\nvar testDescription = 'renderer dependency marketplace visibility';\n"];
foreach ($inert as $addition) { $candidate = $sources; $candidate['catalog'] .= $addition; validateMarketplaceSharedRenderer($candidate); }

$service = (string) file_get_contents($root . '/app/Modules/Catalog/Service/CatalogService.php');
$css = (string) file_get_contents($root . '/assets/frontend/css/veciahorra-frontend.css');
assertMarketplaceVisibility(! str_contains($sources['catalog'], "config.api.get('/catalog/products/'"), 'El listado conserva solicitudes de detalle por producto.');
assertMarketplaceVisibility(! str_contains($sources['catalog'], 'Promise.all(items.map'), 'El listado conserva el patron N+1 anterior.');
assertMarketplaceVisibility(! str_contains($sources['renderer'], 'detail.offers') && ! str_contains($sources['renderer'], 'offer.minimarket') && ! str_contains($sources['renderer'], 'offer.stock'), 'La tarjeta deriva datos desde ofertas parciales.');
assertMarketplaceVisibility(! str_contains($sources['renderer'], 'Disponible en 0 minimarkets'), 'La interfaz contiene un estado imposible de cero minimarkets.');
foreach (['.va-catalog-card__price-prefix', '.va-catalog-card__price-value', '.va-catalog-card__availability'] as $selector) { assertMarketplaceVisibility(str_contains($css, $selector), "Falta estilo acotado para {$selector}."); }
assertMarketplaceVisibility(str_contains($service, "'available_minimarkets' => count(\$summary['minimarkets'])"), 'El contrato no cuenta minimarkets distintos desde el resumen publico.');
assertMarketplaceVisibility(str_contains($service, "\$minimarkets[\$offer['minimarket_id']] = true"), 'El conteo no deduplica Stores por minimarket_id.');
assertMarketplaceVisibility(str_contains($service, "'min_price' => (string) reset(\$prices)"), 'El precio minimo no procede del mismo universo publico.');
assertMarketplaceVisibility(str_contains($service, 'array_chunk($ids, self::READ_BATCH_SIZE)') && str_contains($service, '$this->stores->findActiveByIds($batch)'), 'Los Stores no se resuelven mediante lectura agregada por lotes.');

echo json_encode(['status'=>'PASS','precedence'=>['pre_a2'=>VA_MARKETPLACE_PRE_A2_AUTHORITY,'a2'=>VA_MARKETPLACE_CURRENT_AUTHORITY],'shared_renderer'=>'PASS','asset_dependency'=>'PASS','catalog_delegation'=>'PASS','duplicate_renderer_absent'=>'PASS','dependency_missing_behavior'=>'CONTROLLED_ERROR','real_browser_checks'=>$browser['checks'],'security'=>'PASS','additional_requests'=>0,'adversarial_total'=>count($mutations),'adversarial_rejected'=>$rejected,'inert_total'=>count($inert),'inert_accepted'=>count($inert),'domain_assertions'=>'PASS'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
