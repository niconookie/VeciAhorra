<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2) . '/assets/admin/js/modules/orders/';
$all = '';
foreach (['api.js', 'state.js', 'view.js', 'navigation.js', 'app.js'] as $file) {
    $all .= file_get_contents($root . $file);
}
$checks = [
    ! str_contains($all, 'console.log'),
    ! str_contains($all, 'eval('),
    ! str_contains($all, 'innerHTML'),
    preg_match('/primary_state\s*=(?!=)/', $all) !== 1,
    preg_match('/consistency_state\s*=(?!=)/', $all) !== 1,
    str_contains($all, 'pushState'),
    str_contains($all, 'replaceState'),
    str_contains($all, 'popstate'),
    str_contains($all, 'AbortController'),
    str_contains($all, 'pagehide'),
    str_contains($all, 'textContent'),
    substr_count($all, 'fetch(') === 1,
];
foreach ($checks as $index => $check) {
    if (! $check) throw new RuntimeException("Fallo estructural JS {$index}.");
}
echo 'PASS order-admin-list-javascript-test assertions=' . count($checks) . "\n";
