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
$module = file_get_contents($root . '/assets/admin/js/modules/orders/detail-navigation.js') ?: '';
$view = file_get_contents($root . '/assets/admin/js/modules/orders/view.js') ?: '';
$app = file_get_contents($root . '/assets/admin/js/modules/orders/app.js') ?: '';

$assert(substr_count($module, 'export ') === 1, 'navigation API is not closed');
$assert(str_contains($module, 'buildOrderDetailUrl'), 'detail URL builder is absent');
$assert(substr_count($module, "url.searchParams.set('page'") === 1, 'page has multiple authorities');
$assert(substr_count($module, "url.searchParams.set('action'") === 1, 'action has multiple authorities');
$assert(substr_count($module, "url.searchParams.set('order_id'") === 1, 'order ID has multiple authorities');
$assert(substr_count($module, "'return_") === 9, 'return mapping is not exact');
$assert(str_contains($module, 'normalizeOrdersListContext(listContext)'), 'context is not normalized');
$assert(! str_contains($view, 'return_'), 'view duplicates return mapping');
$assert(str_contains($view, "document.createElement('a')"), 'view action is not a real link');
$assert(str_contains($view, "link.href = href"), 'href is not assigned safely');
$assert(str_contains($view, "link.textContent = 'Ver'"), 'accessible action label is absent');
$assert(! str_contains($view, "link.addEventListener"), 'view action intercepts clicks');
$assert(str_contains($app, "from './detail-navigation.js'"), 'list does not consume navigation layer');
foreach (['detail-api.js', 'detail-state.js'] as $forbidden) {
    $assert(! str_contains($app, $forbidden), 'list imports ' . $forbidden);
}
foreach ([
    'fetch(', 'document.', 'window.', 'location.', 'history.', 'pushState',
    'replaceState', 'popstate', 'AbortController', 'sessionStorage',
    'localStorage', 'innerHTML', 'textContent', 'addEventListener',
] as $forbidden) {
    $assert(! str_contains($module, $forbidden), 'navigation owns forbidden responsibility: ' . $forbidden);
}
foreach ([
    'nonce', '_wpnonce', 'token', 'customer_id', 'email', 'phone',
    'address', 'payment', 'public_id', 'dto', 'timeline',
] as $private) {
    $assert(! str_contains(strtolower($module), $private), 'navigation exposes ' . $private);
}
$assert(! preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $module), 'navigation contains SQL');

echo "PASS order-admin-detail-navigation-infrastructure-test assertions={$assertions}\n";
