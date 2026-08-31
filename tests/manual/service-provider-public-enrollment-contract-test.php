<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$module = file_get_contents($root . '/app/Modules/ServiceProviders/ServiceProviderModule.php');
$routes = file_get_contents($root . '/app/Modules/ServiceProviders/Routes/ServiceProviderRoutes.php');
$script = file_get_contents($root . '/assets/frontend/js/service-providers.js');
$plugin = file_get_contents($root . '/veciahorra.php');
$config = file_get_contents($root . '/app/Core/Config.php');

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($module, "registrationUrl(\$this->pageUrl(self::REGISTRATION, '/prestadores/'))"), 'El registro no retorna a prestadores.');
$assert(! str_contains($module, 'registrationUrl($this->servicesUrl())'), 'Permanece el retorno incorrecto a servicios.');
$assert(str_contains($module, 'data-va-provider-enroll') && str_contains($module, 'Activar mi perfil profesional'), 'Falta CTA de enrolamiento autenticado.');
$assert(str_contains($module, 'current_user_can(ServiceProviderRole::CAPABILITY)') && str_contains($module, 'Ir a mi panel'), 'Falta acceso directo del prestador.');
$assert(str_contains($script, "api.post('/service-provider/enroll', {})") && str_contains($script, 'window.location.assign(button.dataset.vaProviderPanelUrl)'), 'El CTA no enrola y redirige al panel.');
$assert(str_contains($routes, 'registration_disabled') && str_contains($routes, 'registrationEnabled()'), 'El endpoint no conserva el gate de registro.');
$assert(str_contains($routes, 'add_role(ServiceProviderRole::ROLE)') && str_contains($routes, 'add_cap(ServiceProviderRole::CAPABILITY)'), 'El enrolamiento no conserva rol y capacidad idempotentes.');
$assert(preg_match('/Version: 0\\.3\\.5/', $plugin) === 1 && str_contains($plugin, "define('VA_VERSION', '0.3.5')"), 'Version principal incorrecta.');
$assert(str_contains($config, "PLUGIN_VERSION = '0.3.5'") && str_contains($config, "SCHEMA_VERSION = '0.32.0'"), 'Versiones de Config incorrectas.');
$assert(! str_contains($routes, 'COMMERCE_FLAG') && ! str_contains($module, 'COMMERCE_FLAG'), 'El cambio alcanzo el gate de comercio.');

echo "OK service-provider-public-enrollment-contract\n";
