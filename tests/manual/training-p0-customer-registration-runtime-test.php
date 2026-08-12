<?php

declare(strict_types=1);

$base = 'https://localhost/Minimarket';
$ssl = ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true];
$get = stream_context_create(['ssl' => $ssl, 'http' => ['ignore_errors' => true, 'timeout' => 20]]);
$html = file_get_contents($base . '/registro-cliente/', false, $get);

if (! is_string($html) || preg_match('/name="_va_customer_nonce" value="([^"]+)"/', $html, $match) !== 1) {
    throw new RuntimeException('No fue posible obtener el formulario o su nonce.');
}

$email = 'va_p0_cleanup_' . time() . '@example.test';
$payload = http_build_query([
    '_va_customer_nonce' => $match[1],
    'veciahorra_customer_registration' => '1',
    'first_name' => 'Prueba',
    'last_name' => 'P0',
    'email' => $email,
    'password' => 'ClaveP0-2026!',
    'password_confirmation' => 'ClaveP0-2026!',
]);
$post = stream_context_create([
    'ssl' => $ssl,
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($payload) . "\r\n",
        'content' => $payload,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 20,
    ],
]);

file_get_contents($base . '/registro-cliente/', false, $post);
$responseHeaders = $http_response_header ?? [];
$status = $responseHeaders[0] ?? '';
$location = '';
$hasAuthCookie = false;
foreach ($responseHeaders as $header) {
    if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
    if (stripos($header, 'Set-Cookie: wordpress_logged_in_') === 0) $hasAuthCookie = true;
}

define('WP_USE_THEMES', false);
require dirname(__DIR__, 5) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$user = get_user_by('email', $email);
try {
    if (! str_contains($status, '302')) throw new RuntimeException('Registro no respondió 302.');
    if (! str_ends_with($location, '/Minimarket/mis-compras/')) throw new RuntimeException('Redirect de registro incorrecto.');
    if (! $hasAuthCookie) throw new RuntimeException('Registro no emitió cookie de auto-login.');
    if (! $user instanceof WP_User) throw new RuntimeException('Usuario no creado.');
    if ($user->roles !== ['customer']) throw new RuntimeException('Rol creado incorrecto: ' . implode(',', $user->roles));
    echo "PASS registration=created role=customer auto_login=yes redirect=/mis-compras/\n";
} finally {
    if ($user instanceof WP_User) wp_delete_user($user->ID);
    if (get_user_by('email', $email)) throw new RuntimeException('Cleanup de usuario falló.');
    echo "PASS cleanup=user_removed\n";
}
