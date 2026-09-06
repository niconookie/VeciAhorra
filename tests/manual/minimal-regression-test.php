<?php
declare(strict_types=1);
require __DIR__.'/support/minimal-runtime.php';
$minimalSuite=$argv[1]??'';
if ($minimalSuite === 'prebootstrap') {
    $checks = 0;
    foreach (array_merge($minimalNetworkFunctions, get_extension_funcs('sockets') ?: []) as $function) {
        ++$checks;
        if (function_exists($function)) { throw new RuntimeException('NETWORK_FUNCTION_AVAILABLE'); }
    }
    if (!function_exists('is_blog_installed') || !is_blog_installed()) { throw new RuntimeException('WORDPRESS_NOT_LOADED'); }
    echo 'PASS prebootstrap WORDPRESS_LOADED=yes assertions=' . ($checks + 1)
        . ' ini=' . hash_file('sha256', php_ini_loaded_file()) . PHP_EOL;
    return;
}
if(!in_array($minimalSuite,['checkout-reservation-integration-test.php','payment-session-test.php','reservation-order-integration-test.php','webpay-return-rest-route-test.php'],true))throw new RuntimeException('UNAUTHORIZED_REGRESSION');
// These legacy suites resolve Application only after the explicit Mock check above.
require __DIR__.'/'.$minimalSuite;
