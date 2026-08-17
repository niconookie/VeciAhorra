<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assetsFile = $root . '/app/Modules/Frontend/Assets/FrontendAssets.php';
$rendererFile = $root . '/assets/frontend/js/veciahorra-product-card.js';
$catalogFile = $root . '/assets/frontend/js/veciahorra-catalog.js';

/** @param mixed $condition */
function assertCardRenderer($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$assets = file_get_contents($assetsFile);
$renderer = file_get_contents($rendererFile);
$catalog = file_get_contents($catalogFile);

assertCardRenderer(is_string($assets), 'ASSET_REGISTRY_UNREADABLE');
assertCardRenderer(is_string($renderer), 'RENDERER_UNREADABLE');
assertCardRenderer(is_string($catalog), 'CATALOG_UNREADABLE');

assertCardRenderer(
    str_contains($assets, "public const PRODUCT_CARD_SCRIPT_HANDLE = 'veciahorra-product-card';"),
    'RENDERER_HANDLE_MISSING'
);
assertCardRenderer(
    str_contains($assets, "'js/veciahorra-product-card.js'"),
    'RENDERER_ASSET_MISSING'
);
assertCardRenderer(
    preg_match(
        '/self::CATALOG_SCRIPT_HANDLE,\s*\$baseUrl \. \'js\/veciahorra-catalog\.js\',\s*\[self::SCRIPT_HANDLE, self::PRODUCT_CARD_SCRIPT_HANDLE\]/s',
        $assets
    ) === 1,
    'CATALOG_DEPENDENCY_MISSING'
);
assertCardRenderer(
    substr_count($assets, 'wp_enqueue_script(self::PRODUCT_CARD_SCRIPT_HANDLE)') === 0,
    'RENDERER_GLOBALLY_ENQUEUED'
);

$rendererChecks = [
    'window.VeciAhorraProductCard = Object.freeze({ render: render });' => 'GLOBAL_API_MISSING',
    "settings.headingTag === 'h3' ? 'h3' : 'h2'" => 'HEADING_DEFAULT_UNSAFE',
    'safeClass.test(className)' => 'MODIFIER_VALIDATION_MISSING',
    "parsed.protocol === 'http:' || parsed.protocol === 'https:'" => 'URL_PROTOCOL_VALIDATION_MISSING',
    'node.textContent = text' => 'TEXT_CONTENT_MISSING',
    "image.loading = 'lazy'" => 'LAZY_LOADING_MISSING',
    "image.decoding = 'async'" => 'ASYNC_DECODING_MISSING',
    "image.alt = name" => 'IMAGE_ALT_MISSING',
    "'Imagen no disponible'" => 'IMAGE_FALLBACK_MISSING',
    "'Desde'" => 'PRICE_PREFIX_MISSING',
    "money.format(Number(price))" => 'PRICE_FORMAT_MISSING',
    "'Disponible en '" => 'MINIMARKET_COUNT_MISSING',
    "'Ver producto'" => 'PRODUCT_LINK_MISSING',
];

foreach ($rendererChecks as $needle => $message) {
    assertCardRenderer(str_contains($renderer, $needle), $message);
}

assertCardRenderer(!str_contains($renderer, 'innerHTML'), 'INNER_HTML_FORBIDDEN');
assertCardRenderer(!str_contains($renderer, 'fetch('), 'RENDERER_REQUEST_FORBIDDEN');
assertCardRenderer(!str_contains($renderer, '.api.'), 'RENDERER_API_REQUEST_FORBIDDEN');
assertCardRenderer(!str_contains($renderer, 'inventory_id'), 'INVENTORY_SELECTION_FORBIDDEN');
assertCardRenderer(!str_contains($renderer, 'sector'), 'SECTOR_ACCESS_FORBIDDEN');
assertCardRenderer(!preg_match('/\bfunction\s+card\s*\(/', $catalog), 'PRIVATE_RENDERER_REMAINS');
assertCardRenderer(
    str_contains($catalog, "typeof renderer.render !== 'function'"),
    'DEPENDENCY_GUARD_MISSING'
);
assertCardRenderer(
    str_contains($catalog, 'fragment.appendChild(renderer.render(product, {'),
    'CATALOG_DELEGATION_MISSING'
);
assertCardRenderer(substr_count($catalog, "config.api.get('/catalog/categories')") === 1, 'CATEGORY_REQUEST_CHANGED');
assertCardRenderer(substr_count($catalog, "config.api.get(catalogPath(filters))") === 1, 'PRODUCT_REQUEST_CHANGED');

echo json_encode([
    'renderer_api' => 'PASS',
    'asset_wiring' => 'PASS',
    'catalog_delegation' => 'PASS',
    'security_guards' => 'PASS',
    'additional_requests' => 0,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
