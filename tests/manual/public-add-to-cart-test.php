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

function assertPublicAddToCart(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertPublicAddToCartContains(string $needle, string $haystack): void
{
    assertPublicAddToCart(
        str_contains($haystack, $needle),
        "No se encontro el contrato requerido: {$needle}"
    );
}

set_current_screen('front');
wp_set_current_user(0);
$container = new Container();
$assets = $container->make(FrontendAssets::class);
$guestConfig = $assets->configuration();
$secondGuestConfig = $assets->configuration();
$sessionId = $guestConfig['cart']['sessionId'] ?? null;

assertPublicAddToCart(
    is_string($sessionId) && preg_match('/^[a-f0-9]{64}$/', $sessionId) === 1,
    'No se genero identidad opaca valida para invitado.'
);
assertPublicAddToCart(
    $sessionId === ($secondGuestConfig['cart']['sessionId'] ?? null),
    'La identidad invitada no se conserva en la sesion.'
);
assertPublicAddToCart(
    ($guestConfig['cart']['sessionHeader'] ?? null)
        === 'X-Veciahorra-Cart-Session',
    'La configuracion no usa el encabezado real de Cart.'
);

wp_set_current_user(1);
$authenticatedConfig = $assets->configuration();
assertPublicAddToCart(
    ($authenticatedConfig['cart']['sessionId'] ?? null) === '',
    'Un usuario autenticado recibio identidad de invitado.'
);
wp_set_current_user(0);

$controller = $container->make(FrontendController::class);
$html = $controller->renderPlaceholder(['product_id' => 123]);
foreach ([
    'data-va-add-to-cart', 'disabled', 'aria-busy="false"',
    'data-va-add-loading', 'data-va-cart-success', 'aria-live="polite"',
    'data-va-cart-error', 'role="alert"', 'data-va-view-cart',
    'Ver carrito', '/carrito-veciahorra/',
] as $contract) {
    assertPublicAddToCartContains($contract, $html);
}

$root = dirname(__DIR__, 2);
$javascript = (string) file_get_contents(
    $root . '/assets/frontend/js/veciahorra-product-offers.js'
);
$css = (string) file_get_contents(
    $root . '/assets/frontend/css/veciahorra-frontend.css'
);

foreach ([
    'isAddingToCart', 'if (isAddingToCart || !selectedExists)',
    'store.setSelectionLocked(true)', 'store.setSelectionLocked(false)',
    'operationSelection', 'Producto agregado al carrito desde ',
    "config.api.post('/cart/items'", 'inventory_id: selectedId',
    'quantity: 1', 'Object.keys(payload).length !== 2',
    'payload.inventory_id !== selectedId', 'cartRequestOptions',
    'cart.sessionHeader', "setAttribute('aria-busy'",
    '.finally(function ()',
    'viewCart.hidden = false',
    'No fue posible agregar el producto al carrito. Intenta nuevamente.',
] as $contract) {
    assertPublicAddToCartContains($contract, $javascript);
}
assertPublicAddToCart(
    substr_count($javascript, "config.api.post('/cart/items'") === 1,
    'Existe mas de una mutacion al endpoint de Cart.'
);

$addStart = strpos($javascript, 'function addToCart()');
$addEnd = strpos($javascript, "addButton.addEventListener('click'", $addStart);
assertPublicAddToCart($addStart !== false && $addEnd !== false, 'No se aislo addToCart.');
$addSource = substr($javascript, $addStart, $addEnd - $addStart);
foreach ([
    'product_id:', 'minimarket_id:', 'unit_price:', 'price:', 'stock:',
    'available_stock:', 'subtotal:', 'total:', 'customer_id:', 'session_id:',
] as $forbidden) {
    assertPublicAddToCart(
        ! str_contains($addSource, $forbidden),
        "El payload puede incluir el campo prohibido {$forbidden}"
    );
}
foreach (['/checkout', '/reservations', '/orders', '/payments', '/deliveries'] as $forbidden) {
    assertPublicAddToCart(
        ! str_contains($javascript, $forbidden),
        "Se encontro una integracion fuera de alcance: {$forbidden}"
    );
}
foreach ([
    '.veciahorra-frontend .va-cart-action',
    '.veciahorra-frontend .va-cart-action__button[aria-busy="true"]',
    '.veciahorra-frontend .va-cart-action__message--success',
    '.veciahorra-frontend .va-cart-action__message--error',
] as $contract) {
    assertPublicAddToCartContains($contract, $css);
}

echo "PASS public-add-to-cart-test\n";
