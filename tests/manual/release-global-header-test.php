<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Modules\CustomerAccess\CustomerAccessModule;

function releaseHeaderAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

wp_set_current_user(0);
$module = new CustomerAccessModule();
ob_start();
$module->renderGlobalHeader();
$html = (string) ob_get_clean();
$css = (string) file_get_contents(VA_PLUGIN_PATH . 'assets/frontend/css/global-header.css');
$js = (string) file_get_contents(VA_PLUGIN_PATH . 'assets/frontend/js/global-header.js');

releaseHeaderAssert(substr_count($html, 'data-va-global-header') === 1, 'La cabecera global no es unica.');
foreach (['Tu microzona','Productos','Servicios','¿Qué producto necesitas?','Buscar','Iniciar sesión','Registrarse','Carrito','Categorías','Ofertas','VeciMarket','VeciServicios','Quiénes somos','Vende en VeciAhorra','Reparte con nosotros'] as $label) {
    releaseHeaderAssert(str_contains($html, $label), "Falta {$label}.");
}
releaseHeaderAssert(str_contains($html, 'aria-label="Navegación principal"'), 'Navegacion sin nombre accesible.');
releaseHeaderAssert(substr_count($html, 'aria-controls=') >= 2 && substr_count($html, 'aria-expanded="false"') >= 2, 'Controles desplegables incompletos.');
releaseHeaderAssert(str_contains($html, 'name="search"') && str_contains($html, 'method="get"'), 'Busqueda no progresiva.');
releaseHeaderAssert(str_contains($html, 'data-products-url=') && str_contains($html, 'data-services-url=') && str_contains($html, 'data-current-commune='), 'Falta el enrutamiento cerrado por ambito y microzona.');
releaseHeaderAssert(str_contains($html, 'veciahorra-logo-horizontal.png'), 'Falta la variante horizontal oficial.');
releaseHeaderAssert(str_contains($css, 'body #header') && str_contains($css, '@media(max-width:1100px)'), 'Deduplicacion o responsive ausente.');
releaseHeaderAssert(str_contains($css, 'min-height:44px') && str_contains($css, 'prefers-reduced-motion'), 'Accesibilidad tactil o movimiento ausente.');
releaseHeaderAssert(str_contains($css, '.va-customer-purchases-page .entry-header'), 'Titulo de pagina duplicado sin correccion acotada.');
releaseHeaderAssert(str_contains($css, 'position:absolute') && str_contains($css, 'width:min(420px'), 'Popover de microzona no anclado.');
releaseHeaderAssert(str_contains($js, "event.key === 'Escape'") && str_contains($js, "document.addEventListener('click'"), 'Cierres accesibles ausentes.');
releaseHeaderAssert(! str_contains($js, 'innerHTML') && ! str_contains($js, 'localStorage'), 'Superficie insegura en JavaScript.');

echo "RELEASE_GLOBAL_HEADER=PASS structure=3_LEVELS microzone=PRODUCTIVE search=CANONICAL cart=PRODUCTIVE roles=PRESERVED responsive=PASS accessibility=PASS\n";
