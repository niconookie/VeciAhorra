<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$mode = $argv[1] ?? '';
$login = $argv[2] ?? '';
if (! preg_match('/^va_prelaunch_[a-f0-9]{12}$/D', $login)) throw new RuntimeException('Invalid fixture identity.');
if ($mode === 'setup') {
    $id = wp_create_user($login, wp_generate_password(32, true, true), $login . '@example.test');
    if (is_wp_error($id)) throw new RuntimeException('Fixture user unavailable.');
    $userId = (int) $id;
    $expiry = time() + 1800;
    $token = WP_Session_Tokens::get_instance($userId)->create($expiry);
    $secureCookie = wp_generate_auth_cookie($userId, $expiry, 'secure_auth', $token);
    $loggedInCookie = wp_generate_auth_cookie($userId, $expiry, 'logged_in', $token);
    $_COOKIE[LOGGED_IN_COOKIE] = $loggedInCookie;
    wp_set_current_user($userId);
    echo wp_json_encode([
        'cookies' => [
            ['name' => SECURE_AUTH_COOKIE, 'value' => $secureCookie, 'path' => ADMIN_COOKIE_PATH],
            ['name' => LOGGED_IN_COOKIE, 'value' => $loggedInCookie, 'path' => COOKIEPATH],
        ],
        'nonce' => wp_create_nonce('wp_rest'),
    ]);
    exit;
}
if ($mode === 'cleanup') {
    $user = get_user_by('login', $login);
    if ($user instanceof WP_User) wp_delete_user((int) $user->ID);
    echo "CLEANUP=PASS\n";
    exit;
}
throw new RuntimeException('Invalid fixture mode.');
