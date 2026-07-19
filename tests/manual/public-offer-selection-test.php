<?php

declare(strict_types=1);

use VeciAhorra\Core\Container;
use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;
use VeciAhorra\Modules\Frontend\Controller\FrontendController;

require_once dirname(__DIR__, 5) . '/wp-load.php';

if (session_status() === PHP_SESSION_NONE) {
    session_save_path(sys_get_temp_dir());
}

if (! function_exists('set_current_screen')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
    require_once ABSPATH . 'wp-admin/includes/screen.php';
}

function assertOfferSelection(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertOfferSelectionContains(string $needle, string $haystack): void
{
    assertOfferSelection(
        str_contains($haystack, $needle),
        "No se encontro el contrato requerido: {$needle}"
    );
}

set_current_screen('front');
$container = new Container();
$controller = $container->make(FrontendController::class);

ob_start();
$catalog = $controller->renderPlaceholder(['product_id' => 'invalid']);
$invalidDirectOutput = (string) ob_get_clean();
assertOfferSelection(
    $invalidDirectOutput === '',
    'Un product_id invalido produjo salida directa.'
);
assertOfferSelection(
    str_contains($catalog, 'data-va-catalog'),
    'Un product_id invalido no renderiza el catalogo publico vigente.'
);
foreach ([
    'data-va-product-detail', 'data-product-id=', 'data-va-offer-list',
    'product_id="invalid"', 'woocommerce', 'wp-json/wc',
] as $forbiddenInvalidOutput) {
    assertOfferSelection(
        ! str_contains(strtolower($catalog), strtolower($forbiddenInvalidOutput)),
        "Un product_id invalido expuso contenido no permitido: {$forbiddenInvalidOutput}."
    );
}
assertOfferSelection(
    wp_script_is(FrontendAssets::CATALOG_SCRIPT_HANDLE, 'enqueued'),
    'Un product_id invalido no encolo el catalogo publico.'
);
assertOfferSelection(
    ! wp_script_is(FrontendAssets::OFFER_SCRIPT_HANDLE, 'enqueued'),
    'El selector se encolo sin product_id valido.'
);

ob_start();
$html = $controller->renderPlaceholder(['product_id' => '123']);
$directOutput = (string) ob_get_clean();
assertOfferSelection($directOutput === '', 'El shortcode produjo salida directa.');

foreach ([
    'data-va-product-detail',
    'data-product-id="123"',
    'role="radiogroup"',
    'data-va-offer-list',
    'data-va-offers-empty',
    'data-va-selection-summary',
    'aria-live="polite"',
] as $contract) {
    assertOfferSelectionContains($contract, $html);
}
assertOfferSelection(
    wp_script_is(FrontendAssets::OFFER_SCRIPT_HANDLE, 'registered'),
    'No se registro el script de ofertas.'
);
assertOfferSelection(
    wp_script_is(FrontendAssets::OFFER_SCRIPT_HANDLE, 'enqueued'),
    'No se encolo el script de ofertas para la ficha.'
);

global $wp_scripts;
$dependencies = $wp_scripts->registered[
    FrontendAssets::OFFER_SCRIPT_HANDLE
]->deps ?? [];
assertOfferSelection(
    in_array(FrontendAssets::SCRIPT_HANDLE, $dependencies, true),
    'El selector no depende del cliente REST base.'
);

$root = dirname(__DIR__, 2);
$javascript = (string) file_get_contents(
    $root . '/assets/frontend/js/veciahorra-product-offers.js'
);
$css = (string) file_get_contents(
    $root . '/assets/frontend/css/veciahorra-frontend.css'
);

foreach ([
    'selectedInventoryId', 'product_id: productId',
    'inventory_id: inventoryId', 'minimarket_id: minimarketId',
    'unit_price: price', 'available_stock: stock',
    'Number.isFinite(price)', 'isPositiveInteger(stock)',
    'createSelectionStore', 'setProduct', 'getCartPayload', 'quantity: 1',
    "role', 'radio'", 'aria-checked', 'ArrowRight', 'ArrowLeft',
    'ArrowDown', 'ArrowUp', 'Home', 'End', "event.key === ' '",
    "event.key === 'Enter'", 'offer_unavailable', 'invalid_offer',
] as $contract) {
    assertOfferSelectionContains($contract, $javascript);
}

assertOfferSelection(
    substr_count($javascript, "config.api.get('/catalog/products/'") === 1,
    'El selector no usa exactamente una consulta publica de producto.'
);
foreach ([
    '/checkout', '/reservations', '/orders', '/payments',
    'localStorage', 'sessionStorage', 'window.fetch(',
] as $forbidden) {
    assertOfferSelection(
        ! str_contains($javascript, $forbidden),
        "El selector contiene una integracion fuera de alcance: {$forbidden}"
    );
}
assertOfferSelection(
    ! preg_match('/config\.api\.(patch|delete|request)\s*\(/', $javascript),
    'El selector contiene una mutacion fuera de Add To Cart.'
);

foreach ([
    '.veciahorra-frontend .va-offer-grid',
    '.veciahorra-frontend .va-offer-card--selected',
    '.veciahorra-frontend .va-offer-card--unavailable',
    '.veciahorra-frontend .va-offer-card:focus-visible',
    '@media (min-width: 48rem)', '@media (min-width: 80rem)',
] as $contract) {
    assertOfferSelectionContains($contract, $css);
}

echo "PASS public-offer-selection-test\n";
