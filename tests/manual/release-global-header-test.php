<?php

declare(strict_types=1);

$mode = strtolower((string) ($argv[1] ?? 'false'));
if (! in_array($mode, ['false', 'true'], true)) throw new InvalidArgumentException('Usage: php release-global-header-test.php [false|true]');
$registrationEnabled = $mode === 'true';
define('VECIAHORRA_PUBLIC_REGISTRATION_ENABLED', $registrationEnabled);
define('VECIAHORRA_PUBLIC_COMMERCE_ENABLED', false);

require_once dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Core\LaunchGate;
use VeciAhorra\Modules\CustomerAccess\CustomerAccessModule;

function releaseHeaderAssert(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

releaseHeaderAssert(is_bool(constant(LaunchGate::REGISTRATION_FLAG)), 'El flag de registro no es booleano nativo.');
releaseHeaderAssert((new LaunchGate())->registrationEnabled() === $registrationEnabled, 'LaunchGate no refleja el modo solicitado.');
releaseHeaderAssert(LaunchGate::evaluate(true, true, 'production'), 'LaunchGate rechazo true booleano nativo.');
releaseHeaderAssert(! LaunchGate::evaluate(true, false, 'production'), 'LaunchGate acepto false booleano nativo.');
foreach (['true', 'false', '1', 1, null] as $hostileValue) {
    releaseHeaderAssert(! LaunchGate::evaluate(true, $hostileValue, 'production'), 'LaunchGate acepto un valor no booleano.');
}

wp_set_current_user(0);
$module = new CustomerAccessModule();
ob_start();
$module->renderGlobalHeader();
$html = (string) ob_get_clean();
$css = (string) file_get_contents(VA_PLUGIN_PATH . 'assets/frontend/css/global-header.css');
$js = (string) file_get_contents(VA_PLUGIN_PATH . 'assets/frontend/js/global-header.js');

releaseHeaderAssert(substr_count($html, 'data-va-global-header') === 1, 'La cabecera global no es unica.');
foreach (['Tu microzona','Productos','Servicios','¿Qué producto necesitas?','Buscar','Iniciar sesión','Carrito','Categorías','Ofertas','VeciMarket','VeciServicios','Quiénes somos'] as $label) {
    releaseHeaderAssert(str_contains($html, $label), "Falta {$label}.");
}

$registrationLink = preg_match('/<a class="va-global-header__register" href="([^"]+)">Registrarse<\/a>/', $html, $registrationMatch) === 1;
if ($registrationEnabled) {
    releaseHeaderAssert($registrationLink, 'Registrarse no volvio como enlace operativo.');
    releaseHeaderAssert(str_starts_with(html_entity_decode($registrationMatch[1]), home_url('/')), 'Registrarse no enlaza a una ruta productiva local.');
    releaseHeaderAssert(str_contains($html, 'Vende en VeciAhorra') && str_contains($html, 'Reparte con nosotros'), 'Faltan destinos publicos de registro en modo abierto.');
    releaseHeaderAssert(! str_contains($html, 'Disponible desde el 1 de septiembre'), 'El aviso cerrado permanece en modo abierto.');
} else {
    releaseHeaderAssert(! $registrationLink, 'Registrarse permanece como enlace operativo con el gate cerrado.');
    releaseHeaderAssert(str_contains($html, 'Disponible desde el 1 de septiembre'), 'Falta el aviso de registro desde el 1 de septiembre.');
    releaseHeaderAssert(str_contains($html, 'Registros disponibles desde el 1 de septiembre'), 'Falta el aviso de registros de negocio.');
}

releaseHeaderAssert(preg_match('/<a class="va-global-header__account-link"[^>]+>.*?<strong>Iniciar sesión<\/strong>/s', $html) === 1, 'Iniciar sesion no permanece operativo.');
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

echo 'RELEASE_GLOBAL_HEADER=PASS registration=' . ($registrationEnabled ? 'true' : 'false')
    . " native_boolean=PASS navigation=PASS roles=PRESERVED search=CANONICAL cart=PRODUCTIVE accessibility=PASS\n";
