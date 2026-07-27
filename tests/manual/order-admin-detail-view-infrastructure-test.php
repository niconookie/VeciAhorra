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
$view = file_get_contents($root . '/assets/admin/js/modules/orders/detail-view.js') ?: '';
$css = file_get_contents($root . '/assets/admin/css/orders.css') ?: '';
$page = file_get_contents($root . '/app/Modules/Orders/Admin/OrdersPage.php') ?: '';
$app = file_get_contents($root . '/assets/admin/js/modules/orders/detail-app.js') ?: '';

$assert(substr_count($view, 'export ') === 1, 'render API is not closed');
$assert(str_contains($view, 'export function createOrderDetailView'), 'render factory is absent');
$assert(str_contains($view, 'return Object.freeze({ render })'), 'render public API is not minimal');
$assert(str_contains($view, 'root.ownerDocument'), 'render does not use injected DOM ownership');
$assert(str_contains($view, "getAttribute('role') !== 'status'"), 'status region is not validated');
$assert(str_contains($view, "getAttribute('role') !== 'alert'"), 'alert region is not validated');
$assert(str_contains($view, 'errorRegion.tabIndex !== -1'), 'alert focusability is not validated');
$assert(str_contains($view, "root.setAttribute('aria-busy'"), 'aria-busy is not managed');
$assert(str_contains($view, 'replaceChildren()'), 'stale content is not cleared');
$assert(str_contains($view, 'node.textContent = text'), 'remote values are not rendered as text');
$assert(str_contains($view, 'not_found:'), 'not_found contract is absent');
foreach ([
    'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'fetch(',
    'window.', 'VeciAhorra', 'history.', 'pushState', 'replaceState', 'popstate',
    'location.', 'sessionStorage', 'localStorage', 'AbortController',
    'createOrderDetailTransport', 'createOrderDetailState', '.load(',
    'addEventListener', 'setTimeout', 'setInterval',
] as $forbidden) {
    $assert(! str_contains($view, $forbidden), 'render owns forbidden responsibility: ' . $forbidden);
}
foreach ([
    'customer_id', 'email', 'phone', 'address', 'public_id', 'token',
    'provider_payload', 'authorization', 'metadata', 'allowed_actions',
    'mutable_actions', 'operational_version',
] as $private) {
    $assert(! str_contains(strtolower($view), $private), 'render allowlist exposes ' . $private);
}
$assert(! preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $view), 'render contains SQL');
$assert(str_contains($page, "'veciahorra-orders-detail-app'"), 'detail application is not registered');
$assert(
    str_contains($app, "from './detail-view.js'")
    && strpos($app, "from './detail-view.js'") > strpos($app, "from './detail-api.js'"),
    'render dependency is not ordered after transport'
);
$assert(! str_contains($css, '!important'), 'detail CSS uses important');
foreach (explode("\n", $css) as $line) {
    if (! str_contains($line, 'veciahorra-order-detail')) continue;
    $assert(
        str_starts_with(trim($line), '.veciahorra-order-detail')
        || str_starts_with(trim($line), '@media'),
        'detail CSS selector is not scoped'
    );
}
foreach (['http://', 'https://', 'url('] as $external) {
    $assert(! str_contains($css, $external), 'CSS loads an external resource');
}

echo "PASS order-admin-detail-view-infrastructure-test assertions={$assertions}\n";
