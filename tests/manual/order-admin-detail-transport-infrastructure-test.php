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
    $modules = wp_script_modules();
    return (new ReflectionProperty($modules, 'queue'))->getValue($modules);
};
$setRequest = static function (array $query): void {
    $_GET = $query;
    $_SERVER['QUERY_STRING'] = http_build_query($query);
};

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
$assert($admins !== [], 'administrator required');
wp_set_current_user((int) $admins[0]);

$page = new OrdersPage();
$hook = 'orders-transport-test-hook';
(new ReflectionProperty(OrdersPage::class, 'pageHook'))->setValue($page, $hook);
$initialQueue = $queue();

$setRequest(['page' => 'veciahorra-orders', 'action' => 'view', 'order_id' => '0']);
$page->enqueueAssets($hook);
$assert($queue() === $initialQueue, 'invalid detail enqueued a module');
$setRequest(['page' => 'veciahorra-orders', 'action' => 'edit', 'order_id' => '25']);
$page->enqueueAssets($hook);
$assert($queue() === $initialQueue, 'unknown action enqueued a module');
$setRequest(['page' => 'veciahorra-orders', 'action' => 'view', 'order_id' => '25']);
$page->enqueueAssets('other-admin-hook');
$assert($queue() === $initialQueue, 'foreign admin page enqueued a module');

$page->enqueueAssets($hook);
$detailQueue = $queue();
$assert(count(array_keys($detailQueue, 'veciahorra-orders-detail-app', true)) === 1, 'detail application not enqueued exactly once');
$assert(! in_array('veciahorra-orders-admin', $detailQueue, true), 'detail enqueued list module');
$page->enqueueAssets($hook);
$assert($queue() === $detailQueue, 'detail transport was enqueued twice');

$previousGet = $_GET;
$previousQuery = $_SERVER['QUERY_STRING'] ?? null;
ob_start();
try {
    (new OrdersPage())->render();
    $html = (string) ob_get_contents();
} finally {
    ob_end_clean();
    $_GET = $previousGet;
    if ($previousQuery === null) {
        unset($_SERVER['QUERY_STRING']);
    } else {
        $_SERVER['QUERY_STRING'] = $previousQuery;
    }
}
$assert(substr_count($html, 'id="veciahorra-order-detail-config"') === 1, 'detail configuration is not emitted once');
$assert(substr_count($html, 'window.VeciAhorra = window.VeciAhorra || {}') === 1, 'global namespace is not preserved');
$assert(substr_count($html, 'window.VeciAhorra.ordersAdminDetail || {}') === 1, 'detail namespace is not preserved');
$assert(str_contains($html, 'Object.assign('), 'detail configuration replaces its namespace');
preg_match(
    '~window\.VeciAhorra\.ordersAdminDetail \|\| \{\},\s*(\{.*?\})\s*\);~s',
    $html,
    $configMatch
);
$config = json_decode($configMatch[1] ?? '', true);
$assert(is_array($config), 'detail configuration is not valid JSON');
$assert(array_keys($config) === ['enabled', 'orderId', 'restUrl', 'nonce'], 'detail configuration contains unexpected fields');
$assert($config['enabled'] === true && $config['orderId'] === 25, 'detail configuration identity is invalid');
$assert(
    is_string($config['restUrl'])
    && str_ends_with($config['restUrl'], '/veciahorra/v1/orders'),
    'detail REST base is invalid'
);
$assert(is_string($config['nonce']) && $config['nonce'] !== '', 'detail nonce is absent');
$serialized = wp_json_encode($config);
foreach ([
    'customer_id', 'user_id', 'email', 'phone', 'address',
    'payment', 'public_id', 'allowed_actions', 'mutable_actions',
    'return_', 'returnUrl', 'dto',
] as $private) {
    $assert(! str_contains((string) $serialized, $private), 'configuration exposes ' . $private);
}

$source = file_get_contents(dirname(__DIR__, 2) . '/assets/admin/js/modules/orders/detail-api.js') ?: '';
$assert(substr_count($source, 'export ') === 1, 'transport public API is not closed');
$assert(substr_count($source, 'fetch(') === 1, 'transport does not have one fetch site');
foreach ([
    'document.', 'window.', 'location.', 'history.', 'innerHTML',
    'textContent', 'pushState', 'replaceState', 'setTimeout',
    'setInterval', 'AbortController', 'return_search',
] as $forbidden) {
    $assert(! str_contains($source, $forbidden), 'transport owns forbidden responsibility: ' . $forbidden);
}

wp_set_current_user(0);
echo "PASS order-admin-detail-transport-infrastructure-test assertions={$assertions}\n";
