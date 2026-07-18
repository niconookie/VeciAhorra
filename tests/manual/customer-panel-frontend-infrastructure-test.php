<?php

declare(strict_types=1);

use VeciAhorra\Core\Container;
use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;
use VeciAhorra\Modules\Frontend\Controller\FrontendController;
use VeciAhorra\Modules\Frontend\FrontendModule;
use VeciAhorra\Modules\Frontend\Support\ViewRenderer;

require_once dirname(__DIR__, 5) . '/wp-load.php';

global $wp_scripts, $wp_styles;

function assertCustomerPanelFrontend(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function resetCustomerPanelAssets(): void
{
    wp_dequeue_style(FrontendAssets::CUSTOMER_PANEL_STYLE_HANDLE);
    wp_dequeue_script(FrontendAssets::CUSTOMER_PANEL_SCRIPT_HANDLE);
    wp_deregister_style(FrontendAssets::CUSTOMER_PANEL_STYLE_HANDLE);
    wp_deregister_script(FrontendAssets::CUSTOMER_PANEL_SCRIPT_HANDLE);
}

$container = new Container();
$assets = $container->make(FrontendAssets::class);
$controller = new FrontendController(
    $assets,
    $container->make(ViewRenderer::class)
);
$module = new FrontendModule($assets, $controller);
$module->register();

assertCustomerPanelFrontend(
    shortcode_exists(FrontendController::CUSTOMER_PANEL_SHORTCODE),
    'No se registro el shortcode del Panel Cliente.'
);

resetCustomerPanelAssets();
assertCustomerPanelFrontend(
    ! wp_style_is(FrontendAssets::CUSTOMER_PANEL_STYLE_HANDLE, 'enqueued')
        && ! wp_script_is(FrontendAssets::CUSTOMER_PANEL_SCRIPT_HANDLE, 'enqueued'),
    'Los assets se cargaron sin renderizar el shortcode.'
);
assertCustomerPanelFrontend(
    ! $wp_scripts->get_data(FrontendAssets::CUSTOMER_PANEL_SCRIPT_HANDLE, 'before'),
    'Se agrego configuracion sin renderizar el shortcode.'
);

$assets = new FrontendAssets();
$controller = new FrontendController(
    $assets,
    $container->make(ViewRenderer::class)
);
wp_set_current_user(0);
$anonymousHtml = $controller->renderCustomerPanel();
assertCustomerPanelFrontend(str_contains($anonymousHtml, 'Mis compras'), 'Falta el titulo publico.');
assertCustomerPanelFrontend(str_contains($anonymousHtml, 'Iniciar sesi'), 'Falta el acceso publico.');
assertCustomerPanelFrontend(! str_contains($anonymousHtml, 'data-va-customer-panel-mount'), 'El visitante recibio el mount privado.');
assertCustomerPanelFrontend(! str_contains($anonymousHtml, 'compra='), 'La interfaz publica propago compra.');
assertCustomerPanelFrontend(str_contains($anonymousHtml, '<noscript>'), 'Falta noscript publico.');

$originalGet = $_GET;
$originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;
$queryVariants = [
    '',
    'compra=',
    'compra=chk_valid123',
    'compra=invalido',
    'compra=uno&compra=dos',
    'compra=chk_%2Fcodificado',
];

foreach ($queryVariants as $query) {
    parse_str($query, $_GET);
    $_SERVER['REQUEST_URI'] = '/mis-pedidos/' . ($query === '' ? '' : '?' . $query);
    $variantController = new FrontendController(new FrontendAssets(), $container->make(ViewRenderer::class));
    assertCustomerPanelFrontend(
        $variantController->renderCustomerPanel() === $anonymousHtml,
        "La interfaz publica cambio para la variante {$query}."
    );
}

$_GET = $originalGet;

if ($originalRequestUri === null) {
    unset($_SERVER['REQUEST_URI']);
} else {
    $_SERVER['REQUEST_URI'] = $originalRequestUri;
}

$loginHref = '';
assertCustomerPanelFrontend(
    preg_match('/href="([^"]+)"[^>]*>Iniciar sesi/u', $anonymousHtml, $loginMatch) === 1,
    'No se encontro el enlace de login escapado.'
);
$loginHref = html_entity_decode($loginMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
assertCustomerPanelFrontend(
    $loginHref === wp_login_url(home_url('/mis-pedidos/')),
    'El retorno de login no apunta a la lista canonica.'
);
assertCustomerPanelFrontend(! str_contains($loginHref, 'compra'), 'El retorno de login propaga compra.');

$wp_scripts->remove(FrontendAssets::CUSTOMER_PANEL_SCRIPT_HANDLE);
wp_dequeue_style(FrontendAssets::CUSTOMER_PANEL_STYLE_HANDLE);
wp_deregister_style(FrontendAssets::CUSTOMER_PANEL_STYLE_HANDLE);

$users = get_users(['number' => 1, 'fields' => 'ids']);
assertCustomerPanelFrontend($users !== [], 'La prueba requiere un usuario WordPress.');
wp_set_current_user((int) $users[0]);
$authenticatedAssets = new FrontendAssets();
$authenticatedController = new FrontendController(
    $authenticatedAssets,
    $container->make(ViewRenderer::class)
);
$html = $authenticatedController->renderCustomerPanel();
$duplicateHtml = $authenticatedController->renderCustomerPanel();
$thirdHtml = $authenticatedController->renderCustomerPanel();

assertCustomerPanelFrontend(str_contains($html, 'class="veciahorra-frontend va-customer-panel"'), 'Falta la raiz encapsulada.');
assertCustomerPanelFrontend(str_contains($html, 'data-va-customer-panel-mount'), 'Falta el mount autenticado.');
assertCustomerPanelFrontend(str_contains($html, '<noscript>'), 'Falta noscript autenticado.');
assertCustomerPanelFrontend(! preg_match('/chk_|purchase|order_id|payment/i', $html), 'El shell contiene datos o contratos de compras.');
assertCustomerPanelFrontend(str_contains($duplicateHtml, 'va-customer-panel--duplicate'), 'La segunda instancia no fue neutralizada.');
assertCustomerPanelFrontend(! str_contains($duplicateHtml, 'data-va-customer-panel-mount'), 'La segunda instancia creo otro mount.');
assertCustomerPanelFrontend(! str_contains($duplicateHtml . $thirdHtml, 'id="'), 'Los avisos duplicados agregaron IDs.');
assertCustomerPanelFrontend(! str_contains($duplicateHtml . $thirdHtml, 'aria-live'), 'Los avisos duplicados agregaron regiones live.');
assertCustomerPanelFrontend(! str_contains($duplicateHtml . $thirdHtml, '<noscript>'), 'Los avisos duplicados agregaron noscript.');
assertCustomerPanelFrontend(wp_style_is(FrontendAssets::CUSTOMER_PANEL_STYLE_HANDLE, 'enqueued'), 'No se encolo el CSS.');
assertCustomerPanelFrontend(wp_script_is(FrontendAssets::CUSTOMER_PANEL_SCRIPT_HANDLE, 'enqueued'), 'No se encolo el JavaScript.');
assertCustomerPanelFrontend(
    count(array_keys($wp_styles->queue, FrontendAssets::CUSTOMER_PANEL_STYLE_HANDLE, true)) === 1,
    'El CSS se agrego mas de una vez a la cola.'
);
assertCustomerPanelFrontend(
    count(array_keys($wp_scripts->queue, FrontendAssets::CUSTOMER_PANEL_SCRIPT_HANDLE, true)) === 1,
    'El JavaScript se agrego mas de una vez a la cola.'
);

$inline = implode("\n", (array) $wp_scripts->get_data(FrontendAssets::CUSTOMER_PANEL_SCRIPT_HANDLE, 'before'));
assertCustomerPanelFrontend(substr_count($inline, 'window.VeciAhorra = window.VeciAhorra || {}') === 1, 'La configuracion no preserva la global una sola vez.');
assertCustomerPanelFrontend(substr_count($inline, 'window.VeciAhorra.customerPanel || {}') === 1, 'La configuracion no preserva customerPanel una sola vez.');
assertCustomerPanelFrontend(str_contains($inline, '"enabled":true'), 'La configuracion no habilita el panel.');
assertCustomerPanelFrontend(! preg_match('/nonce|user|purchase|order|restUrl/i', $inline), 'La configuracion expone datos innecesarios.');

$javascript = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/frontend/js/customer-panel.js');
foreach (['fetch', 'XMLHttpRequest', 'decodeURIComponent'] as $forbidden) {
    assertCustomerPanelFrontend(! str_contains($javascript, $forbidden), "JavaScript contiene {$forbidden}.");
}
assertCustomerPanelFrontend(
    substr_count($javascript, 'api.get(ENDPOINT, options)') === 1
        && substr_count($javascript, 'api.get(DETAIL_ENDPOINT + encodeURIComponent(publicId), options)') === 1,
    'El JavaScript no conserva una única invocación por endpoint.'
);
assertCustomerPanelFrontend(str_contains($javascript, "svg.setAttribute('aria-hidden', 'true')"), 'Los iconos no son decorativos.');
assertCustomerPanelFrontend(str_contains($javascript, "svg.setAttribute('focusable', 'false')"), 'Los iconos pueden recibir foco.');
assertCustomerPanelFrontend(str_contains($javascript, "STATUS_DECORATION[status.code] || 'neutral'"), 'Los estados desconocidos no usan variante neutral.');
assertCustomerPanelFrontend(
    ! preg_match('/checkout_public_id[^;\n]*(?:slice|substring|substr)\s*\(/', $javascript),
    'El JavaScript trunca el identificador público.'
);
assertCustomerPanelFrontend(
    substr_count($javascript, "document.querySelector('[data-va-customer-panel-mount]')") === 1,
    'JavaScript busca mas de un mount funcional.'
);
assertCustomerPanelFrontend(
    str_contains($javascript, "vaCustomerPanelInitialized === 'true'")
        && str_contains($javascript, "vaCustomerPanelInitialized = 'true'"),
    'JavaScript no protege la inicializacion repetida.'
);

$css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/frontend/css/customer-panel.css');
assertCustomerPanelFrontend(str_contains($css, 'inline-size: min(100%, 76rem);'), 'El panel no conserva el ancho máximo auditado.');
assertCustomerPanelFrontend(str_contains($css, '.va-customer-panel__detail-overview'), 'Falta el layout del overview.');
assertCustomerPanelFrontend(str_contains($css, '.va-customer-panel__detail-services'), 'Falta el layout de pago y entrega.');
assertCustomerPanelFrontend(str_contains($css, '.va-customer-panel__detail-item-content'), 'Falta el layout estructural de productos.');
assertCustomerPanelFrontend(str_contains($css, '.va-customer-panel__status-badge'), 'Faltan los badges de estado.');
assertCustomerPanelFrontend(str_contains($css, '.va-customer-panel__purchase-action'), 'Falta la acción visual de la tarjeta.');
assertCustomerPanelFrontend(str_contains($css, '.va-customer-panel__purchase-main'), 'Falta el área principal compacta del listado.');
assertCustomerPanelFrontend(str_contains($css, '.va-customer-panel__purchase-aside'), 'Falta el área lateral del listado.');
assertCustomerPanelFrontend(str_contains($css, '.va-customer-panel__purchase-metadata'), 'Falta la cuadrícula responsive de metadatos.');
assertCustomerPanelFrontend(str_contains($css, 'overflow-wrap: anywhere;'), 'Los identificadores largos no tienen wrapping seguro.');
assertCustomerPanelFrontend(str_contains($css, '.va-customer-panel__detail-row--total'), 'Falta la jerarquía visual del total.');
assertCustomerPanelFrontend(str_contains($css, '.va-customer-panel__detail-primary-card'), 'Falta la tarjeta visual principal del detalle.');
assertCustomerPanelFrontend(str_contains($css, '.va-customer-panel__detail-order-header'), 'Falta la cabecera visual del minimarket.');
assertCustomerPanelFrontend(str_contains($css, '.va-customer-panel__timeline-entry::before'), 'Faltan nodos visuales del timeline.');
assertCustomerPanelFrontend(str_contains($css, 'object-fit: contain;'), 'Las imágenes de producto no usan contain.');
assertCustomerPanelFrontend(! str_contains($css, '!important'), 'El CSS introduce !important.');
assertCustomerPanelFrontend(! preg_match('/url\s*\(|@font-face/i', $css), 'El CSS introduce URLs o fuentes externas.');
assertCustomerPanelFrontend(! preg_match('/font-awesome|material-icons|bootstrap|tailwind/i', $css . $javascript), 'Se introdujo una librería visual externa.');
assertCustomerPanelFrontend(! preg_match('/(?:block|inline-)?size\s*:\s*\d+(?:px|rem)\s*;[^}]*overflow\s*:\s*hidden/is', $css), 'El layout combina altura rígida con recorte.');
$cssSelectors = preg_replace('/@media[^\{]+\{/', '', $css) ?? $css;
foreach (preg_split('/}\s*/', $cssSelectors) ?: [] as $rule) {
    $selector = trim((string) strstr($rule, '{', true));

    if ($selector === '') {
        continue;
    }

    foreach (explode(',', $selector) as $part) {
        assertCustomerPanelFrontend(
            str_contains(trim($part), '.va-customer-panel'),
            "Selector CSS fuera de la raiz: {$part}"
        );
    }
}

echo "PASS customer-panel-frontend-infrastructure-test\n";
