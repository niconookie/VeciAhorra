<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Requests\OrderAdminPageRequest;

require_once dirname(__DIR__, 5) . '/wp-load.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$search = 'té &="東京" %26 action=edit <b>';
$detailQuery = [
    'page' => 'veciahorra-orders',
    'action' => 'view',
    'order_id' => '25',
    'return_search' => $search,
    'return_store_id' => '3',
    'return_order_status' => 'paid',
    'return_fulfillment_mode' => 'pickup',
    'return_date_from' => '2026-07-01',
    'return_date_to' => '2026-07-27',
    'return_sort' => 'updated',
    'return_paged' => '4',
    'return_per_page' => '50',
    'token' => 'private',
    'unknown' => 'discard',
];
$request = new OrderAdminPageRequest($detailQuery);
$assert($request->isValidDetail(), 'detail URL is not accepted');
$assert($request->orderId() === 25, 'detail order ID changed');
$expected = [
    'search' => $search,
    'store_id' => 3,
    'order_status' => 'paid',
    'fulfillment_mode' => 'pickup',
    'sort' => 'updated',
    'per_page' => 50,
    'date_from' => '2026-07-01',
    'date_to' => '2026-07-27',
    'paged' => 4,
];
$assert($request->returnQuery() === $expected, 'validated return context changed');
$returnUrl = $request->returnUrl();
$parts = wp_parse_url($returnUrl);
parse_str($parts['query'] ?? '', $returned);
$assert(($returned['page'] ?? null) === 'veciahorra-orders', 'return page is absent');
foreach ($expected as $name => $value) {
    $assert(
        (string) ($returned[$name] ?? '') === (string) $value,
        $name . ' did not return: ' . wp_json_encode($returned[$name] ?? null)
    );
    $assert(! array_key_exists('return_' . $name, $returned), 'return prefix survived for ' . $name);
}
foreach (['action', 'order_id', 'token', 'unknown', 'nonce', '_wpnonce'] as $forbidden) {
    $assert(! array_key_exists($forbidden, $returned), $forbidden . ' survived return');
}
$assert(($returned['search'] ?? null) === $search, 'special search did not round trip');

$partial = new OrderAdminPageRequest([
    'page' => 'veciahorra-orders',
    'action' => 'view',
    'order_id' => '25',
    'return_search' => 'seguro',
    'return_store_id' => '01',
    'return_order_status' => 'paid',
    'return_date_from' => '2026-02-30',
    'return_date_to' => '2026-07-20',
    'return_per_page' => '25',
    'return_sort' => 'oldest',
]);
$assert($partial->isValidDetail(), 'invalid return dimension invalidated detail');
$assert($partial->returnQuery() === [
    'search' => 'seguro',
    'order_status' => 'paid',
    'sort' => 'oldest',
    'date_to' => '2026-07-20',
], 'invalid dimensions were not omitted independently');
$partialUrl = $partial->returnUrl();
$assert(str_contains($partialUrl, 'search=seguro'), 'valid companion was removed');
$assert(str_contains($partialUrl, 'order_status=paid'), 'valid filter was removed');
$assert(! str_contains($partialUrl, 'store_id='), 'invalid store survived');
$assert(! str_contains($partialUrl, 'date_from='), 'impossible date survived');
$assert(! str_contains($partialUrl, 'per_page='), 'invalid page size survived');

echo "PASS order-admin-detail-navigation-round-trip-test assertions={$assertions}\n";
