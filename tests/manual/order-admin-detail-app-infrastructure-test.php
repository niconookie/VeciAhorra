<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Admin\OrdersPage;

require_once dirname(__DIR__, 5) . '/wp-load.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$queue = static function (): array {
    return (new ReflectionProperty(wp_script_modules(), 'queue'))->getValue(wp_script_modules());
};
$setRequest = static function (array $query): void {
    $_GET = $query;
    $_SERVER['QUERY_STRING'] = http_build_query($query);
};

$root = dirname(__DIR__, 2);
$app = file_get_contents($root . '/assets/admin/js/modules/orders/detail-app.js') ?: '';
$page = file_get_contents($root . '/app/Modules/Orders/Admin/OrdersPage.php') ?: '';
$shell = file_get_contents($root . '/app/Modules/Orders/Views/admin-detail.php') ?: '';

$assert(substr_count($app, 'export ') === 1, 'application API is not closed');
$assert(str_contains($app, 'export function initializeOrderDetailApp'), 'initializer is absent');
foreach ([
    "from './detail-api.js'", "from './detail-state.js'", "from './detail-view.js'",
] as $import) {
    $assert(str_contains($app, $import), 'certified dependency is absent: ' . $import);
}
$assert(substr_count($app, 'createOrderDetailTransport(') === 1, 'transport construction is not singular');
$assert(substr_count($app, 'createOrderDetailState(') === 1, 'state construction is not singular');
$assert(substr_count($app, 'createOrderDetailView(') === 1, 'view construction is not singular');
$assert(substr_count($app, 'state.getSnapshot()') === 1, 'initial snapshot render is not singular');
$assert(substr_count($app, 'state.subscribe(') === 1, 'subscription is not singular');
$assert(substr_count($app, 'state.load()') === 1, 'initial load is not singular');
$assert(substr_count($app, "addEventListener('pagehide'") === 1, 'pagehide listener is not singular');
$assert(substr_count($app, "removeEventListener('pagehide'") === 1, 'pagehide listener is not cleaned');
$assert(str_contains($app, 'unsubscribe()'), 'cleanup does not unsubscribe');
$assert(str_contains($app, 'state.destroy()'), 'cleanup does not destroy state');
$assert(str_contains($app, 'initializeOrderDetailApp();'), 'entrypoint does not bootstrap');
$assert(str_contains($app, 'url.origin !== window.location.origin'), 'REST origin is not validated');
$assert(
    str_contains($app, 'veciahorra\\/v1\\/orders') && str_contains($app, 'url.pathname'),
    'REST path is not validated'
);
foreach ([
    'innerHTML', 'history.', 'pushState', 'replaceState', 'popstate',
    'sessionStorage', 'localStorage', 'console.', 'JSON.stringify',
    'fetch(', 'XMLHttpRequest', 'setTimeout', 'setInterval', 'beforeunload',
    'keepalive', 'customer_id', 'public_id', 'allowed_actions',
    'mutable_actions', 'detail-navigation.js',
] as $forbidden) {
    $assert(! str_contains($app, $forbidden), 'application owns forbidden responsibility: ' . $forbidden);
}
$assert(! preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $app), 'application contains SQL');
$assert(substr_count($page, 'wp_enqueue_script_module(') === 2, 'page has unexpected module entrypoints');
$assert(str_contains($page, "'veciahorra-orders-detail-app'"), 'detail entrypoint is not registered');
$assert(! str_contains($page, "'veciahorra-orders-detail-transport'"), 'transport is redundantly enqueued');
$assert(! str_contains($page, "'veciahorra-orders-detail-view'"), 'view is redundantly enqueued');
$assert(
    strpos($shell, 'window.VeciAhorra.ordersAdminDetail') !== false,
    'detail configuration is absent'
);

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
$assert($admins !== [], 'administrator required');
wp_set_current_user((int) $admins[0]);
$pageObject = new OrdersPage();
(new ReflectionProperty(OrdersPage::class, 'pageHook'))->setValue($pageObject, 'app-test-hook');
$initial = $queue();
foreach ([
    ['page' => 'veciahorra-orders'],
    ['page' => 'veciahorra-orders', 'action' => 'edit', 'order_id' => '25'],
    ['page' => 'veciahorra-orders', 'action' => 'view', 'order_id' => '0'],
] as $invalid) {
    $setRequest($invalid);
    $pageObject->enqueueAssets('other-hook');
    $assert($queue() === $initial, 'foreign or invalid route enqueued detail application');
}
$setRequest(['page' => 'veciahorra-orders', 'action' => 'view', 'order_id' => '25']);
$pageObject->enqueueAssets('app-test-hook');
$detailQueue = $queue();
$assert(count(array_keys($detailQueue, 'veciahorra-orders-detail-app', true)) === 1, 'detail app not queued once');
$assert(! in_array('veciahorra-orders-admin', $detailQueue, true), 'detail loaded list app');
$pageObject->enqueueAssets('app-test-hook');
$assert($queue() === $detailQueue, 'detail app was queued twice');

wp_set_current_user(0);
echo "PASS order-admin-detail-app-infrastructure-test assertions={$assertions}\n";
