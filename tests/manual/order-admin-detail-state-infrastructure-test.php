<?php

declare(strict_types=1);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$root = dirname(__DIR__, 2);
$path = $root . '/assets/admin/js/modules/orders/detail-state.js';
$source = file_get_contents($path) ?: '';

$assert(is_file($path), 'state module is absent');
$assert(substr_count($source, 'export ') === 1, 'state public API is not closed');
$assert(
    str_contains($source, 'export function createOrderDetailState'),
    'state factory is not exported'
);
foreach (['getSnapshot', 'subscribe', 'load', 'cancel', 'destroy'] as $method) {
    $assert(str_contains($source, $method), 'state API misses ' . $method);
}
foreach ([
    'idle', 'loading', 'ready', 'unauthorized', 'forbidden', 'not_found',
    'invalid_request', 'server_error', 'network_error', 'invalid_response',
] as $status) {
    $assert(str_contains($source, "'{$status}'"), 'state misses status ' . $status);
}
foreach ([
    'fetch(', 'document.', 'window.', 'location.', 'history.', 'URL(',
    'URLSearchParams', 'innerHTML', 'textContent', 'pushState', 'replaceState',
    'setTimeout', 'setInterval', 'VeciAhorra', '/admin', '/orders',
    'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ',
] as $forbidden) {
    $assert(! str_contains($source, $forbidden), 'state owns forbidden responsibility: ' . $forbidden);
}
$assert(substr_count($source, 'new AbortController()') === 1, 'controller ownership is not singular');
$assert(substr_count($source, 'transport.getOrderDetail(') === 1, 'transport call site is not singular');
$assert(! str_contains($source, 'detail-api.js'), 'state creates or imports the transport');

echo "PASS order-admin-detail-state-infrastructure-test assertions={$assertions}\n";
