<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Admin\OrdersPage;
use VeciAhorra\Modules\Orders\Requests\OrderAdminPageRequest;

require_once dirname(__DIR__, 5) . '/wp-load.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$same = static function (mixed $expected, mixed $actual, string $message) use ($assert): void {
    $assert($expected === $actual, $message . ': ' . var_export([$expected, $actual], true));
};
$render = static function (array $query, ?string $rawQuery = null): string {
    $previousGet = $_GET;
    $previousQuery = $_SERVER['QUERY_STRING'] ?? null;
    $_GET = $query;
    $_SERVER['QUERY_STRING'] = $rawQuery ?? http_build_query($query);
    ob_start();
    try {
        (new OrdersPage())->render();
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
        $_GET = $previousGet;
        if ($previousQuery === null) {
            unset($_SERVER['QUERY_STRING']);
        } else {
            $_SERVER['QUERY_STRING'] = $previousQuery;
        }
    }
};

$same(OrderAdminPageRequest::SCREEN_LIST, (new OrderAdminPageRequest([]))->screen(), 'missing action keeps list');
foreach (['1', '25', (string) PHP_INT_MAX] as $id) {
    $request = new OrderAdminPageRequest(['action' => 'view', 'order_id' => $id]);
    $same(OrderAdminPageRequest::SCREEN_DETAIL, $request->screen(), 'canonical ID rejected');
    $same((int) $id, $request->orderId(), 'canonical ID changed');
}
foreach ([null, '', '0', '01', '+1', '-1', '1.0', '1e2', ' 1', '1 ', '1x', ['1'], str_repeat('9', 100)] as $id) {
    $query = ['action' => 'view'];
    if ($id !== null) {
        $query['order_id'] = $id;
    }
    $same(OrderAdminPageRequest::SCREEN_INVALID_DETAIL, (new OrderAdminPageRequest($query))->screen(), 'invalid ID accepted');
}
foreach (['', 'edit', 'detail', 'View', ' view', 'view '] as $action) {
    $same(
        OrderAdminPageRequest::SCREEN_UNKNOWN_ACTION,
        (new OrderAdminPageRequest(['action' => $action, 'order_id' => '1']))->screen(),
        'unknown action accepted'
    );
}
$same(
    OrderAdminPageRequest::SCREEN_UNKNOWN_ACTION,
    (new OrderAdminPageRequest(['action' => ['view'], 'order_id' => '1']))->screen(),
    'array action accepted'
);
$same(
    OrderAdminPageRequest::SCREEN_UNKNOWN_ACTION,
    (new OrderAdminPageRequest(['action' => 'view', 'order_id' => '1'], ['action']))->screen(),
    'duplicate action accepted'
);
$same(
    OrderAdminPageRequest::SCREEN_INVALID_DETAIL,
    (new OrderAdminPageRequest(['action' => 'view', 'order_id' => '1'], ['order_id']))->screen(),
    'duplicate order ID accepted'
);

$allReturns = new OrderAdminPageRequest([
    'action' => 'view',
    'order_id' => '25',
    'return_search' => 'checkout:25',
    'return_store_id' => '3',
    'return_order_status' => 'paid',
    'return_fulfillment_mode' => 'pickup',
    'return_date_from' => '2026-07-01',
    'return_date_to' => '2026-07-27',
    'return_sort' => 'updated',
    'return_paged' => '4',
    'return_per_page' => '50',
    'unknown' => 'discarded',
    '_wpnonce' => 'discarded',
    'token' => 'discarded',
]);
$same([
    'search' => 'checkout:25',
    'store_id' => 3,
    'order_status' => 'paid',
    'fulfillment_mode' => 'pickup',
    'sort' => 'updated',
    'per_page' => 50,
    'date_from' => '2026-07-01',
    'date_to' => '2026-07-27',
    'paged' => 4,
], $allReturns->returnQuery(), 'nine return parameters changed');
$parts = wp_parse_url($allReturns->returnUrl());
parse_str((string) ($parts['query'] ?? ''), $returnQuery);
$same('veciahorra-orders', $returnQuery['page'] ?? null, 'return slug changed');
foreach (['action', 'order_id', 'return_search', 'unknown', '_wpnonce', 'token'] as $forbidden) {
    $assert(! array_key_exists($forbidden, $returnQuery), 'return URL kept forbidden parameter');
}
$same(wp_parse_url(admin_url('admin.php'), PHP_URL_HOST), $parts['host'] ?? null, 'return URL changed host');
$same(wp_parse_url(admin_url('admin.php'), PHP_URL_PATH), $parts['path'] ?? null, 'return URL changed path');

foreach ([
    'return_search' => ['', ' search', str_repeat('x', 101), '1.5', 'checkout:0', ['bad']],
    'return_store_id' => ['0', '01', '-1', '1.5', ['3']],
    'return_order_status' => ['cancelled', 'Paid', ['paid']],
    'return_fulfillment_mode' => ['courier', 'Pickup', ['pickup']],
    'return_date_from' => ['2026-02-30', '2026-7-01', ['2026-07-01']],
    'return_date_to' => ['not-a-date', '2026-07-01 ', ['2026-07-01']],
    'return_sort' => ['id DESC', 'UPDATED', ['updated']],
    'return_paged' => ['0', '01', '1e2', ['2']],
    'return_per_page' => ['10', '25', '050', ['50']],
] as $key => $invalidValues) {
    foreach ($invalidValues as $invalidValue) {
        $companion = $key === 'return_sort'
            ? ['return_order_status' => 'paid']
            : ['return_sort' => 'oldest'];
        $request = new OrderAdminPageRequest(array_merge(
            ['action' => 'view', 'order_id' => '2'],
            $companion,
            [$key => $invalidValue]
        ));
        $expectedCompanion = $key === 'return_sort'
            ? ['order_status' => 'paid']
            : ['sort' => 'oldest'];
        $same($expectedCompanion, $request->returnQuery(), 'invalid return removed valid companion');
    }
}
$validCompanion = ['action' => 'view', 'order_id' => '2', 'return_sort' => 'oldest'];
$duplicateReturn = new OrderAdminPageRequest(
    $validCompanion + ['return_search' => 'safe'],
    ['return_search']
);
$same(['sort' => 'oldest'], $duplicateReturn->returnQuery(), 'duplicate return parameter survived');

$onlyFrom = new OrderAdminPageRequest($validCompanion + ['return_date_from' => '2026-07-10']);
$same(['sort' => 'oldest', 'date_from' => '2026-07-10'], $onlyFrom->returnQuery(), 'partial from date rejected');
$onlyTo = new OrderAdminPageRequest($validCompanion + ['return_date_to' => '2026-07-20']);
$same(['sort' => 'oldest', 'date_to' => '2026-07-20'], $onlyTo->returnQuery(), 'partial to date rejected');
$inverted = new OrderAdminPageRequest($validCompanion + [
    'return_date_from' => '2026-07-20',
    'return_date_to' => '2026-07-10',
]);
$same(['sort' => 'oldest'], $inverted->returnQuery(), 'inverted dates were not both discarded');

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
$assert($admins !== [], 'administrator required');
wp_set_current_user((int) $admins[0]);

$assetPage = new OrdersPage();
$hookProperty = new ReflectionProperty(OrdersPage::class, 'pageHook');
$hookProperty->setValue($assetPage, 'orders-test-hook');
$previousGet = $_GET;
$previousQuery = $_SERVER['QUERY_STRING'] ?? null;
$_GET = ['page' => 'veciahorra-orders', 'action' => 'view', 'order_id' => '25'];
$_SERVER['QUERY_STRING'] = 'page=veciahorra-orders&action=view&order_id=25';
$assetPage->enqueueAssets('orders-test-hook');
$modules = wp_script_modules();
$registered = (new ReflectionProperty($modules, 'registered'))->getValue($modules);
$assert(! isset($registered['veciahorra-orders-admin']), 'detail route loaded list JavaScript');
$assert(isset($registered['veciahorra-orders-detail-transport']), 'detail route did not load transport');
$assert(isset($registered['veciahorra-orders-detail-view']), 'detail route did not load render');
$_GET = ['page' => 'veciahorra-orders'];
$_SERVER['QUERY_STRING'] = 'page=veciahorra-orders';
$assetPage->enqueueAssets('orders-test-hook');
$registered = (new ReflectionProperty($modules, 'registered'))->getValue($modules);
$assert(isset($registered['veciahorra-orders-admin']), 'list route lost its JavaScript');
$_GET = $previousGet;
if ($previousQuery === null) {
    unset($_SERVER['QUERY_STRING']);
} else {
    $_SERVER['QUERY_STRING'] = $previousQuery;
}

$queriesBefore = (int) $GLOBALS['wpdb']->num_queries;
$detailHtml = $render([
    'page' => 'veciahorra-orders',
    'action' => 'view',
    'order_id' => '25',
    'return_sort' => 'oldest',
]);
$same($queriesBefore, (int) $GLOBALS['wpdb']->num_queries, 'detail shell executed SQL');
$same(1, substr_count($detailHtml, '<h1>'), 'detail shell must have one H1');
foreach ([
    'id="veciahorra-order-detail"',
    'Detalle administrativo de pedido',
    'Volver a pedidos',
    'aria-busy="true"',
    'id="veciahorra-order-detail-loading"',
    'role="status"',
    'id="veciahorra-order-detail-error"',
    'role="alert"',
    'id="veciahorra-order-detail-content"',
    '<main',
] as $required) {
    $assert(str_contains($detailHtml, $required), 'detail shell misses ' . $required);
}
foreach ([
    'customer_id',
    'payment.session.public_id',
    'allowed_actions',
    'mutable_actions',
    'fetch(',
    'OrderAdminReadService',
    'veciahorra-orders-config',
    '<form',
] as $forbidden) {
    $assert(! str_contains($detailHtml, $forbidden), 'detail shell contains forbidden material');
}
$assert(str_contains($detailHtml, 'sort=oldest'), 'return link lost valid context');

$missingIdHtml = $render(
    ['page' => 'veciahorra-orders', 'action' => 'view'],
    'page=veciahorra-orders&action=view'
);
$assert(str_contains($missingIdHtml, 'Order solicitada no es válida'), 'missing ID error is unsafe or absent');
$assert(! str_contains($missingIdHtml, 'veciahorra-order-detail"'), 'invalid ID rendered detail shell');
$unknownHtml = $render([
    'page' => 'veciahorra-orders',
    'action' => 'edit',
    'order_id' => '25',
]);
$assert(str_contains($unknownHtml, 'acción administrativa solicitada no es válida'), 'unknown action error absent');
$assert(! str_contains($unknownHtml, 'id="veciahorra-orders-admin"'), 'unknown action fell back to list');
$duplicateHtml = $render(
    ['page' => 'veciahorra-orders', 'action' => 'view', 'order_id' => '25'],
    'page=veciahorra-orders&action=view&order_id=25&order_id=26'
);
$assert(str_contains($duplicateHtml, 'Order solicitada no es válida'), 'duplicate ID accepted by global parser');

$listHtml = $render(['page' => 'veciahorra-orders']);
$assert(str_contains($listHtml, 'id="veciahorra-orders-admin"'), 'list view no longer renders');
$assert(str_contains($listHtml, 'id="veciahorra-orders-filters"'), 'list filters changed');
$assert(str_contains($listHtml, 'id="veciahorra-orders-pagination"'), 'list pagination changed');
$assert(str_contains($listHtml, 'id="veciahorra-orders-config"'), 'list config changed');
$assert(! str_contains($listHtml, 'id="veciahorra-order-detail"'), 'list rendered detail shell');

$root = dirname(__DIR__, 2);
$pageSource = file_get_contents($root . '/app/Modules/Orders/Admin/OrdersPage.php') ?: '';
$detailSource = file_get_contents($root . '/app/Modules/Orders/Views/admin-detail.php') ?: '';
foreach (['fetch(', 'wp_remote_', 'OrderAdminReadService', 'getOrderDetail', 'rest_url('] as $forbidden) {
    $assert(! str_contains($detailSource, $forbidden), 'detail view contains forbidden operation');
}
$assert(substr_count($pageSource, 'wp_enqueue_script_module(') === 3, 'unexpected frontend modules were added');
$assert(str_contains($pageSource, 'assets/admin/js/modules/orders/detail-api.js'), 'detail transport asset is absent');
$assert(str_contains($pageSource, 'assets/admin/js/modules/orders/detail-view.js'), 'detail render asset is absent');

wp_set_current_user(0);
echo "PASS order-admin-detail-shell-test assertions={$assertions} shell_queries=0 rest_requests=0\n";
