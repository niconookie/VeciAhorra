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

$notFound = strpos($script, 'if (error.status === 404)');
$forbidden = strpos($script, 'if (error.status === 403)', $notFound ?: 0);
$unauthorized = strpos($script, 'if (error.status === 401)', $forbidden ?: 0);
$submit = strpos($script, "form.addEventListener('submit'", $unauthorized ?: 0);
$notFoundBranch = $notFound !== false && $forbidden !== false ? substr($script, $notFound, $forbidden - $notFound) : '';
$forbiddenBranch = $forbidden !== false && $unauthorized !== false ? substr($script, $forbidden, $unauthorized - $forbidden) : '';
$unauthorizedBranch = $unauthorized !== false && $submit !== false ? substr($script, $unauthorized, $submit - $unauthorized) : '';
$submitFlow = $submit !== false ? substr($script, $submit, 1200) : '';

$assert(str_contains($notFoundBranch, 'enrolled = true;'), '404 no autoriza el primer guardado.');
$assert(str_contains($notFoundBranch, 'Completa los datos y guarda tu borrador.'), '404 no muestra el mensaje esperado.');
$assert(! str_contains($notFoundBranch, '/service-provider/enroll'), '404 duplica el enrolamiento.');
$assert(str_contains($forbiddenBranch, "api.post('/service-provider/enroll', {})") && str_contains($forbiddenBranch, 'enrolled = true;'), '403 no conserva el enrolamiento existente.');
$assert(str_contains($unauthorizedBranch, 'Inicia sesi') && ! str_contains($unauthorizedBranch, 'enrolled = true;'), '401 permite guardar sin autenticacion.');
$assert(str_contains($submitFlow, 'if (!enrolled)') && str_contains($submitFlow, "api.post('/service-provider/profile', formPayload(form))"), 'El primer submit autorizado no llama profile.');
$assert(substr_count($forbiddenBranch, "api.post('/service-provider/enroll', {})") === 1, '403 puede duplicar enroll.');

$assert(str_contains($module, "registrationUrl(\$this->pageUrl(self::REGISTRATION, '/prestadores/'))"), 'El registro no retorna a prestadores.');
$assert(! str_contains($module, 'registrationUrl($this->servicesUrl())'), 'Permanece el retorno incorrecto a servicios.');
$assert(str_contains($module, 'data-va-provider-enroll') && str_contains($module, 'Activar mi perfil profesional'), 'Falta CTA de enrolamiento autenticado.');
$assert(str_contains($module, 'current_user_can(ServiceProviderRole::CAPABILITY)') && str_contains($module, 'Ir a mi panel'), 'Falta acceso directo del prestador.');
$assert(str_contains($script, "api.post('/service-provider/enroll', {})") && str_contains($script, 'window.location.assign(button.dataset.vaProviderPanelUrl)'), 'El CTA no enrola y redirige al panel.');
$assert(str_contains($routes, 'registration_disabled') && str_contains($routes, 'registrationEnabled()'), 'El endpoint no conserva el gate de registro.');
$assert(str_contains($routes, 'add_role(ServiceProviderRole::ROLE)') && str_contains($routes, 'add_cap(ServiceProviderRole::CAPABILITY)'), 'El enrolamiento no conserva rol y capacidad idempotentes.');
$assert(preg_match('/Version: 0\\.3\\.6/', $plugin) === 1 && str_contains($plugin, "define('VA_VERSION', '0.3.6')"), 'Version principal incorrecta.');
$assert(str_contains($config, "PLUGIN_VERSION = '0.3.6'") && str_contains($config, "SCHEMA_VERSION = '0.32.0'"), 'Versiones de Config incorrectas.');
$assert(! str_contains($routes, 'COMMERCE_FLAG') && ! str_contains($module, 'COMMERCE_FLAG'), 'El cambio alcanzo el gate de comercio.');

echo "OK service-provider-public-enrollment-contract\n";
