<?php

declare(strict_types=1);

use VeciAhorra\Modules\CustomerAccess\CustomerAccessModule;
use VeciAhorra\Modules\ServiceProviders\ServiceProviderModule;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertGlobalHeader(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function headerMenuArgs(): stdClass
{
    return (object) ['theme_location' => 'menu_1'];
}

/** @return list<object> */
function headerFixture(): array
{
    $links = [
        ['Inicio', home_url('/')],
        ['Servicios', home_url('/servicios/')],
        ['Mis compras', home_url('/mis-compras/')],
        ['Contacto', home_url('/contacto/')],
    ];

    return array_map(static function (array $link): object {
        return (object) [
            'title' => $link[0],
            'url' => $link[1],
            'classes' => [],
        ];
    }, $links);
}

/** @return list<string> */
function normalizedTitles(): array
{
    return array_map(
        static fn (object $item): string => (string) $item->title,
        apply_filters('wp_nav_menu_objects', headerFixture(), headerMenuArgs())
    );
}

wp_set_current_user(0);
$guestTitles = normalizedTitles();
$purchases = array_search('Mis compras', $guestTitles, true);
assertGlobalHeader(is_int($purchases), 'Mis compras no está presente para el cliente/visitante.');
assertGlobalHeader(($guestTitles[$purchases + 1] ?? '') === 'Servicios', 'Servicios no sigue a Mis compras.');

$guestAccess = apply_filters('wp_nav_menu_items', '', headerMenuArgs());
assertGlobalHeader(str_contains($guestAccess, 'Registrarse'), 'Falta Registrarse para visitante.');
assertGlobalHeader(str_contains($guestAccess, 'Iniciar sesión'), 'Falta Iniciar sesión para visitante.');

$roles = [
    'customer' => true,
    'veciahorra_minimarket' => false,
    'veciahorra_courier' => false,
    'veciahorra_service_provider' => false,
];

foreach ($roles as $role => $keepsPurchases) {
    $users = get_users(['role' => $role, 'number' => 1]);
    assertGlobalHeader($users !== [], "Falta usuario de capacitación para {$role}.");
    wp_set_current_user((int) $users[0]->ID);
    $titles = normalizedTitles();
    assertGlobalHeader(in_array('Servicios', $titles, true), "Falta Servicios para {$role}.");
    assertGlobalHeader(
        in_array('Mis compras', $titles, true) === $keepsPurchases,
        "Visibilidad incorrecta de Mis compras para {$role}."
    );
    if ($keepsPurchases) {
        $index = array_search('Mis compras', $titles, true);
        assertGlobalHeader(is_int($index) && ($titles[$index + 1] ?? '') === 'Servicios', 'Orden incorrecto para customer.');
    }
    $access = apply_filters('wp_nav_menu_items', '', headerMenuArgs());
    assertGlobalHeader(! str_contains($access, 'Iniciar sesión'), "Login visible para {$role}.");
    assertGlobalHeader(! str_contains($access, 'Registrarse'), "Registro visible para {$role}.");
    assertGlobalHeader(str_contains($access, 'Mi panel'), "Falta panel para {$role}.");
    assertGlobalHeader(
        str_contains($access, esc_html((string) $users[0]->display_name)),
        "Falta nombre autenticado para {$role}."
    );
}

$landing = (new ServiceProviderModule(new CustomerAccessModule()))->landing();
assertGlobalHeader(! str_contains($landing, 'va-sp-header'), 'La landing conserva una cabecera propia.');

$css = file_get_contents(VA_PLUGIN_PATH . 'assets/frontend/css/global-header.css');
assertGlobalHeader(is_string($css) && str_contains($css, '.site-branding .site-title'), 'Falta ocultar el título textual.');
assertGlobalHeader(str_contains($css, 'object-fit: contain'), 'Falta preservar la proporción del logo.');
assertGlobalHeader(str_contains($css, '@media (max-width: 48rem)'), 'Falta adaptación móvil.');

echo "HEADER_SHARED=PASS\n";
echo "GUEST_NAVIGATION=PASS\n";
echo "CUSTOMER_NAVIGATION=PASS\n";
echo "STORE_NAVIGATION=PASS\n";
echo "COURIER_NAVIGATION=PASS\n";
echo "SERVICE_PROVIDER_NAVIGATION=PASS\n";
echo "SERVICES_AFTER_PURCHASES=PASS\n";
echo "LOGIN_REGISTER_GUEST=PASS\n";
echo "LOGIN_REGISTER_AUTHENTICATED=PASS\n";
echo "TITLE_HIDDEN=PASS\n";
echo "LOGO_LAYOUT=PASS\n";
echo "MOBILE_LAYOUT_RULES=PASS\n";
