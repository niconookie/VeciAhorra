<?php

declare(strict_types=1);

use VeciAhorra\Modules\CustomerAccess\CustomerAccessModule;

if (! defined('VECIAHORRA_PUBLIC_REGISTRATION_ENABLED')) {
    define('VECIAHORRA_PUBLIC_REGISTRATION_ENABLED', true);
}
require_once dirname(__DIR__, 5) . '/wp-load.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) throw new RuntimeException($message);
};
$module = new CustomerAccessModule();
$previousGet = $_GET;
$previousPost = $_POST;
$previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
$provider = home_url('/prestadores/');
$customer = home_url('/mis-compras/');

$render = static function (mixed $redirect = null) use ($module): string {
    $_GET = $redirect === null ? [] : ['redirect_to' => $redirect];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    return $module->renderRegistration();
};
$hidden = static function (string $html, string $name): string {
    if (preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $match) !== 1) {
        throw new RuntimeException("Falta hidden {$name}.");
    }
    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
};

try {
    $normal = $render();
    $assert(str_contains($normal, 'Crear cuenta de cliente'), 'Cambio el titulo historico de cliente.');
    $assert(str_contains($normal, 'Regístrate para consultar tus compras y comprar en los comercios de tu barrio.'), 'Cambio el texto historico de cliente.');
    $assert(str_contains($normal, '>Crear mi cuenta</button>'), 'Cambio el boton historico.');

    $unrelated = $render(home_url('/servicios/'));
    $assert(str_contains($unrelated, 'Crear cuenta de cliente'), 'Redirect interno no relacionado cambio el contexto.');

    foreach (['local', 'featured', 'communal'] as $plan) {
        $html = $render($provider . '?plan=' . $plan);
        $destination = $hidden($html, 'redirect_to');
        $assert(str_contains($html, 'CUENTA VECIAHORRA') && str_contains($html, 'Crear cuenta de prestador'), "Falta contexto para {$plan}.");
        $assert(str_contains($html, 'Crea tu cuenta VeciAhorra para continuar con el registro de tu servicio.'), "Falta texto para {$plan}.");
        $assert($destination === $provider . '?plan=' . $plan, "Destino no canonico para {$plan}.");
        $assert(str_contains($html, esc_url(wp_login_url($destination))), "Login no conserva {$plan}.");

        $_GET = [];
        $_POST = [
            'veciahorra_customer_registration' => '1',
            'redirect_to' => $destination,
            '_va_customer_nonce' => $hidden($html, '_va_customer_nonce'),
            '_va_registration_context' => $hidden($html, '_va_registration_context'),
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $post = $module->renderRegistration();
        $assert(str_contains($post, 'Crear cuenta de prestador') && $hidden($post, 'redirect_to') === $destination, "POST perdio {$plan}.");
    }

    $hostile = [
        'https://evil.example/prestadores/?plan=local',
        '//example.test/prestadores/?plan=local',
        $provider . '-malicioso/?plan=local',
        home_url('/prestadores/../prestadores/?plan=local'),
        home_url('/prestadores/%252e%252e/?plan=local'),
        $provider . '?plan=',
        $provider . '?plan=premium',
        $provider . '?plan=LOCAL',
        $provider . '?plan=%20local',
        $provider . '?plan[]=local',
        $provider . '?plan=local&plan=featured',
        $provider . '?plan=local&next=customer',
    ];
    foreach ($hostile as $url) {
        $html = $render($url);
        $assert(! str_contains($html, 'Crear cuenta de prestador'), "Destino hostil activo contexto: {$url}");
    }

    $valid = $render($provider . '?plan=local');
    $_GET = [];
    $_POST = [
        'veciahorra_customer_registration' => '1',
        'redirect_to' => $provider . '?plan=featured',
        '_va_customer_nonce' => $hidden($valid, '_va_customer_nonce'),
        '_va_registration_context' => $hidden($valid, '_va_registration_context'),
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $tampered = $module->renderRegistration();
    $assert(! str_contains($tampered, 'Crear cuenta de prestador'), 'Manipulacion GET/POST cambio el contexto.');
    $assert(untrailingslashit($hidden($tampered, 'redirect_to')) === untrailingslashit($customer), 'Manipulacion GET/POST no uso fallback.');

    $customerUser = new WP_User();
    $customerUser->roles = ['customer'];
    $customerUser->caps = ['customer' => true];
    $login = $module->loginRedirect($customer, $provider . '?plan=communal', $customerUser);
    $assert($login === $provider . '?plan=communal', 'Login no continuo al plan comunal.');

    $source = (string) file_get_contents(VA_PLUGIN_PATH . 'app/Modules/CustomerAccess/CustomerAccessModule.php');
    $assert(str_contains($source, "set_role('customer')") && ! str_contains($source, "set_role('veciahorra_service_provider')"), 'Registro altero el rol general.');
    echo "PASS provider-registration-context assertions={$assertions} roles=customer external_calls=0 payments=0\n";
} finally {
    $_GET = $previousGet;
    $_POST = $previousPost;
    if ($previousMethod === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $previousMethod;
}
