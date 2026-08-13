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

function assertPublicCheckout(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertPublicCheckoutContains(string $needle, string $haystack): void
{
    assertPublicCheckout(
        str_contains($haystack, $needle),
        "No se encontro el contrato requerido: {$needle}"
    );
}

set_current_screen('front');
$container = new Container();
$assets = $container->make(FrontendAssets::class);
$config = $assets->configuration();
assertPublicCheckout(
    ($config['checkout']['minimumDeliveryAmount'] ?? null) === 8000,
    'El minimo inicial de despacho no es 8000.'
);
assertPublicCheckout(
    isset($config['pages']['cart'], $config['pages']['checkout']),
    'Falta navegacion publica de carrito/checkout.'
);

$minimumFilter = static fn (): int => 7999;
add_filter('veciahorra_minimum_delivery_amount', $minimumFilter);
$filteredConfig = (new FrontendAssets())->configuration();
remove_filter('veciahorra_minimum_delivery_amount', $minimumFilter);
assertPublicCheckout(
    ($filteredConfig['checkout']['minimumDeliveryAmount'] ?? null) === 7999,
    'El minimo no utiliza la fuente configurable.'
);
foreach ([-1, 1.5, NAN, INF, true, false, [], new stdClass(), '8000abc'] as $invalidMinimum) {
    $invalidFilter = static fn () => $invalidMinimum;
    add_filter('veciahorra_minimum_delivery_amount', $invalidFilter);
    $invalidConfig = (new FrontendAssets())->configuration();
    remove_filter('veciahorra_minimum_delivery_amount', $invalidFilter);
    assertPublicCheckout(
        ($invalidConfig['checkout']['minimumDeliveryAmount'] ?? null) === 8000,
        'Una configuracion invalida no uso fallback seguro.'
    );
}

$controller = $container->make(FrontendController::class);
$customers = get_users(['role' => 'customer', 'number' => 1]);
assertPublicCheckout($customers !== [], 'Falta usuario cliente para probar identidad de checkout.');
wp_set_current_user((int) $customers[0]->ID);
$html = $controller->renderCheckout();
foreach ([
    'data-va-checkout', 'data-va-checkout-loading', 'data-va-checkout-empty',
    'data-va-checkout-groups', 'data-va-checkout-total',
    'data-va-checkout-form', 'data-va-checkout-buyer-name', 'Comprador',
    'name="phone"', 'name="email"', 'data-va-delivery-options',
    'data-va-delivery-fields', 'name="recipient_name"', 'Nombre de quien recibe',
    'name="address"', 'name="commune"',
    'name="reference"', 'name="notes"', 'Crear pedido',
    'data-va-checkout-result', 'Pedido creado correctamente.',
    'aria-live="polite"', 'novalidate',
] as $contract) {
    assertPublicCheckoutContains($contract, $html);
}
assertPublicCheckout(! str_contains($html, 'name="first_name"'), 'Checkout vuelve a pedir nombre del comprador.');
assertPublicCheckout(! str_contains($html, 'name="last_name"'), 'Checkout vuelve a pedir apellido del comprador.');
assertPublicCheckoutContains(esc_html((string) $customers[0]->display_name), $html);
assertPublicCheckout(
    shortcode_exists(FrontendController::CHECKOUT_SHORTCODE),
    'No se registro el shortcode publico de checkout.'
);
assertPublicCheckout(
    wp_script_is(FrontendAssets::CHECKOUT_SCRIPT_HANDLE, 'enqueued'),
    'No se encolo el asset de checkout.'
);

$checkoutUrlFilter = static fn (): string => home_url('/checkout-test/');
add_filter('veciahorra_frontend_checkout_url', $checkoutUrlFilter);
$cartHtml = $controller->renderCart();
remove_filter('veciahorra_frontend_checkout_url', $checkoutUrlFilter);
assertPublicCheckoutContains('data-va-cart-checkout', $cartHtml);
assertPublicCheckoutContains('Continuar al checkout', $cartHtml);
assertPublicCheckoutContains('data-va-cart-continue-shopping', $cartHtml);
assertPublicCheckoutContains('Seguir comprando', $cartHtml);
$unavailableUrlFilter = static fn (): string => '';
add_filter('veciahorra_frontend_checkout_url', $unavailableUrlFilter);
$unavailableCartHtml = $controller->renderCart();
remove_filter('veciahorra_frontend_checkout_url', $unavailableUrlFilter);
assertPublicCheckoutContains(
    'data-va-cart-checkout-unavailable',
    $unavailableCartHtml
);

$root = dirname(__DIR__, 2);
$javascript = (string) file_get_contents(
    $root . '/assets/frontend/js/veciahorra-checkout.js'
);
$css = (string) file_get_contents(
    $root . '/assets/frontend/css/veciahorra-frontend.css'
);

foreach ([
    "config.api.get('/cart'", 'normalizedGroups', 'decimalToCents',
    'positiveInteger', 'REQUEST_TIMEOUT',
    "config.api.post(", "'/checkout/validate'", '{}',
    'normalizedValidation', 'normalizedCheckout', 'Validando…',
    'Compra validada correctamente.', 'Creando pedido…',
    'minimumDeliveryAmount', 'deliveryOption(', "'pickup'",
    "'delivery'", 'summary.totalCents >= minimumCents',
    'function checkoutPayload()', 'recipient_name:', 'contact_phone:',
    'address_line1:', 'commune:', 'reference:', 'notes:',
    "['recipient_name', 'address', 'commune']", 'aria-invalid', 'aria-describedby',
    'event.preventDefault()', "'/checkout'", 'Resultado pendiente',
    "'/payments/session'", 'paymentIdempotencyKey',
    "form.method = 'POST'", 'form.action = redirect.href',
    "input.name = 'token_ws'", 'input.value = token',
    'document.body.append(form)', 'form.submit()', 'form.remove()',
    'Debes iniciar sesión para crear el pedido.',
    'Recarga la página y revisa tus pedidos',
    '[400, 401, 403, 409, 422]',
    '/payment-status', 'pollPaymentStatus', 'poll_after_ms',
    'paymentInFlight', 'pagehide', 'stopPaymentPolling',
    'Date.now() - paymentStartedAt > 300000',
    'rememberCheckout', "url.searchParams.set('checkout_id', checkoutId)",
    'window.history.replaceState', 'resumePaymentStatus',
    "data.checkout_id !== checkoutId", "typeof data.status !== 'string'",
    'La sesión de pago se está preparando.',
    'Estamos verificando si el pago pudo iniciarse',
] as $contract) {
    assertPublicCheckoutContains($contract, $javascript);
}
assertPublicCheckout(
    str_contains($controller->renderCheckout(), 'data-va-payment-status-panel'),
    'Checkout no contiene la region accesible de estado de pago.'
);
assertPublicCheckout(
    substr_count($javascript, "config.api.get('/cart'") === 1,
    'Checkout debe cargar el carrito una vez por cada load.'
);
foreach ([
    'config.api.patch', 'config.api.delete',
    '/orders', '/reservations', '/deliveries',
    'localStorage', 'sessionStorage',
] as $forbidden) {
    assertPublicCheckout(
        ! str_contains($javascript, $forbidden),
        "Checkout contiene operacion prohibida: {$forbidden}"
    );
}
assertPublicCheckout(
    substr_count($javascript, "'/checkout/validate'") === 1,
    'Debe existir una sola llamada al endpoint de validacion.'
);
assertPublicCheckout(
    substr_count($javascript, "'/checkout'") === 1,
    'Debe existir una sola llamada al endpoint transaccional.'
);
assertPublicCheckout(
    substr_count($javascript, 'checkoutPayload()') === 3,
    'Validate y create deben compartir un unico builder de payload.'
);
assertPublicCheckout(
    substr_count($javascript, "'/payments/session'") === 1,
    'Debe existir una sola llamada al inicio durable de pago.'
);
assertPublicCheckout(
    ! str_contains($javascript, 'window.location.assign('),
    'Checkout navega a Webpay mediante GET.'
);
assertPublicCheckout(
    substr_count($javascript, "input.name = 'token_ws'") === 1,
    'El formulario Webpay no contiene exactamente un token_ws.'
);
assertPublicCheckout(
    ! str_contains($javascript, 'paymentAction.href = data.redirect_url'),
    'La recuperacion de PaymentSession navega a Webpay mediante GET.'
);
foreach ([
    '.veciahorra-frontend .va-checkout',
    '.veciahorra-frontend .va-checkout-form__grid',
    '.veciahorra-frontend .va-field [aria-invalid="true"]',
    '@media (min-width: 48rem)',
] as $contract) {
    assertPublicCheckoutContains($contract, $css);
}

$changed = shell_exec('git status --short 2>&1') ?? '';
foreach ([
    'app/Modules/Delivery/',
] as $forbiddenPath) {
    assertPublicCheckout(
        ! str_contains(str_replace('\\', '/', $changed), $forbiddenPath),
        "Se modifico un modulo cerrado: {$forbiddenPath}"
    );
}
echo "PASS public-checkout-test\n";
