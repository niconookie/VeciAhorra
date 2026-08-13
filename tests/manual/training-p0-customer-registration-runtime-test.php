<?php

declare(strict_types=1);

$base = 'https://localhost/Minimarket';
$ssl = ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true];

/** @return array{status:string,location:string,auth_cookie:bool,body:string} */
function registrationRequest(string $url, ?array $payload = null): array
{
    global $ssl;
    $http = ['ignore_errors' => true, 'timeout' => 20, 'follow_location' => 0];
    if ($payload !== null) {
        $body = http_build_query($payload);
        $http += [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n",
            'content' => $body,
        ];
    }
    $response = file_get_contents($url, false, stream_context_create(['ssl' => $ssl, 'http' => $http]));
    $headers = $http_response_header ?? [];
    $location = '';
    $hasAuthCookie = false;
    foreach ($headers as $header) {
        if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
        if (stripos($header, 'Set-Cookie: wordpress_logged_in_') === 0) $hasAuthCookie = true;
    }
    return [
        'status' => $headers[0] ?? '',
        'location' => $location,
        'auth_cookie' => $hasAuthCookie,
        'body' => is_string($response) ? $response : '',
    ];
}

/** @return array{nonce:string,redirect_to:string} */
function registrationForm(string $url): array
{
    $response = registrationRequest($url);
    if (
        preg_match('/name="_va_customer_nonce" value="([^"]+)"/', $response['body'], $nonce) !== 1
        || preg_match('/name="redirect_to" value="([^"]*)"/', $response['body'], $redirect) !== 1
    ) {
        throw new RuntimeException('No fue posible obtener el formulario, nonce o redirect_to.');
    }
    foreach (['first_name', 'last_name'] as $name) {
        if (preg_match('/<input[^>]+name="' . $name . '"[^>]+type="text"/', $response['body']) !== 1) {
            throw new RuntimeException("{$name} no conserva type=text.");
        }
    }
    if (
        ! str_contains($response['body'], 'va-customer-registration-page')
        || ! str_contains($response['body'], 'veciahorra-customer-registration-css')
        || ! str_contains($response['body'], 'va-customer-registration__grid')
    ) {
        throw new RuntimeException('Falta estructura o asset visual del registro.');
    }
    return ['nonce' => html_entity_decode($nonce[1]), 'redirect_to' => html_entity_decode($redirect[1])];
}

/** @return array<string,string> */
function registrationPayload(string $email, string $nonce, string $redirectTo): array
{
    return [
        '_va_customer_nonce' => $nonce,
        'veciahorra_customer_registration' => '1',
        'redirect_to' => $redirectTo,
        'first_name' => 'Prueba',
        'last_name' => 'P0',
        'email' => $email,
        'password' => 'ClaveP0-2026!',
        'password_confirmation' => 'ClaveP0-2026!',
    ];
}

$registrationUrl = $base . '/registro-cliente/';
$servicesUrl = $base . '/servicios/';
$panelUrl = $base . '/mis-compras/';
$suffix = time() . '_' . bin2hex(random_bytes(3));
$emails = [
    'valid' => "va_p0_services_{$suffix}@example.test",
    'external' => "va_p0_external_{$suffix}@example.test",
    'default' => "va_p0_default_{$suffix}@example.test",
    'invalid_nonce' => "va_p0_nonce_{$suffix}@example.test",
];
$createdUsers = [];

define('WP_USE_THEMES', false);
require dirname(__DIR__, 5) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

try {
    $validForm = registrationForm(add_query_arg('redirect_to', $servicesUrl, $registrationUrl));
    if ($validForm['redirect_to'] !== $servicesUrl) throw new RuntimeException('GET interno no se conservó en hidden.');
    $valid = registrationRequest($registrationUrl, registrationPayload($emails['valid'], $validForm['nonce'], $validForm['redirect_to']));
    if (! str_contains($valid['status'], '302') || untrailingslashit($valid['location']) !== untrailingslashit($servicesUrl) || ! $valid['auth_cookie']) {
        throw new RuntimeException('Registro con redirect_to interno no terminó en /servicios/.');
    }
    $createdUsers[] = get_user_by('email', $emails['valid']);

    wp_set_current_user(0);
    wp_clear_auth_cookie();
    $externalForm = registrationForm(add_query_arg('redirect_to', 'https://evil.example/', $registrationUrl));
    if (untrailingslashit($externalForm['redirect_to']) !== untrailingslashit($panelUrl)) throw new RuntimeException('URL externa no cayó en el fallback.');
    $external = registrationRequest($registrationUrl, registrationPayload($emails['external'], $externalForm['nonce'], 'https://evil.example/'));
    if (! str_contains($external['status'], '302') || untrailingslashit($external['location']) !== untrailingslashit($panelUrl)) {
        throw new RuntimeException('POST externo no cayó en /mis-compras/.');
    }
    $createdUsers[] = get_user_by('email', $emails['external']);

    wp_set_current_user(0);
    wp_clear_auth_cookie();
    foreach (['', '://malformed'] as $invalid) {
        $form = registrationForm(add_query_arg('redirect_to', $invalid, $registrationUrl));
        if (untrailingslashit($form['redirect_to']) !== untrailingslashit($panelUrl)) throw new RuntimeException('Valor vacío o malformado no cayó en el fallback.');
    }
    $arrayForm = registrationForm($registrationUrl . '?redirect_to%5B%5D=' . rawurlencode($servicesUrl));
    if (untrailingslashit($arrayForm['redirect_to']) !== untrailingslashit($panelUrl)) throw new RuntimeException('redirect_to no escalar no cayó en el fallback.');

    $defaultForm = registrationForm($registrationUrl);
    if (untrailingslashit($defaultForm['redirect_to']) !== untrailingslashit($panelUrl)) throw new RuntimeException('Registro normal no conservó /mis-compras/.');
    $default = registrationRequest($registrationUrl, registrationPayload($emails['default'], $defaultForm['nonce'], $defaultForm['redirect_to']));
    if (! str_contains($default['status'], '302') || untrailingslashit($default['location']) !== untrailingslashit($panelUrl)) {
        throw new RuntimeException('Registro normal no terminó en /mis-compras/.');
    }
    $createdUsers[] = get_user_by('email', $emails['default']);

    wp_set_current_user(0);
    wp_clear_auth_cookie();
    $invalidNonce = registrationRequest($registrationUrl, registrationPayload($emails['invalid_nonce'], 'invalid', $servicesUrl));
    if (
        get_user_by('email', $emails['invalid_nonce'])
        || $invalidNonce['location'] !== ''
        || ! str_contains($invalidNonce['body'], 'role="alert"')
    ) {
        throw new RuntimeException('Nonce inválido creó usuario, redirigió o perdió la alerta accesible.');
    }

    foreach ($createdUsers as $user) {
        if (! $user instanceof WP_User || $user->roles !== ['customer']) throw new RuntimeException('Usuario o rol temporal incorrecto.');
    }
    echo "PASS redirect_to=get_post_internal external_rejected malformed_empty_array_rejected\n";
    echo "PASS registration=customer auto_login=yes redirect=/servicios/ default=/mis-compras/\n";
    echo "PASS nonce_invalid=no_user_no_redirect\n";
} finally {
    wp_set_current_user(0);
    wp_clear_auth_cookie();
    foreach ($emails as $email) {
        $user = get_user_by('email', $email);
        if ($user instanceof WP_User) wp_delete_user($user->ID);
        if (get_user_by('email', $email)) throw new RuntimeException('Cleanup de usuario falló: ' . $email);
    }
    echo "PASS cleanup=users_removed\n";
}
