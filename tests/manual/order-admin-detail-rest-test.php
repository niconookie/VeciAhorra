<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalStateResolver;
use VeciAhorra\Modules\Orders\Routes\OrdersAdminRoutes;
use VeciAhorra\Modules\Orders\Services\OrderAdminReadService;
use VeciAhorra\Modules\Orders\Services\OrderOperationalFactsAssembler;
use VeciAhorra\Tests\Manual\Support\InstrumentedOrderAdminReadRepository;
use VeciAhorra\Tests\Manual\Support\OrderAdminReadFixture;

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once __DIR__ . '/support/OrderAdminReadTestSupport.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$makeRoutes = static function (InstrumentedOrderAdminReadRepository $repository): OrdersAdminRoutes {
    return new OrdersAdminRoutes(new OrderAdminReadService(
        $repository,
        new OrderOperationalFactsAssembler(),
        new OrderOperationalStateResolver(),
        '2026-07-27T12:00:00Z'
    ));
};
$request = static function (string $id, array $query = [], string $body = ''): WP_REST_Request {
    $request = new WP_REST_Request('GET', '/veciahorra/v1/orders/' . $id . '/admin');
    $request->set_url_params(['id' => $id]);
    $request->set_query_params($query);
    if ($body !== '') {
        $request->set_body($body);
    }
    return $request;
};
$header = static fn (WP_REST_Response $response): string =>
    (string) ($response->get_headers()['Cache-Control'] ?? '');

$base = OrderAdminReadFixture::base(10);
$bundle = OrderAdminReadFixture::bundle(10);
$repository = new InstrumentedOrderAdminReadRepository([$base], [10 => $bundle]);
$routes = $makeRoutes($repository);
$routes->register();

$registered = rest_get_server()->get_routes();
$routeKey = '/veciahorra/v1/orders/(?P<id>[^/]+)/admin';
$assert(isset($registered[$routeKey]), 'exact detail route is registered');
$detailHandlers = array_values(array_filter(
    $registered[$routeKey],
    static fn (array $handler): bool => ($handler['callback'] ?? null) === [$routes, 'show']
));
$assert(count($detailHandlers) === 1, 'detail route has exactly one show handler');
$assert(($detailHandlers[0]['methods']['GET'] ?? false) === true, 'detail route accepts GET');
$assert(($detailHandlers[0]['permission_callback'] ?? null) === [$routes, 'authorize'], 'detail route reuses authorization');

$adminIds = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
$assert($adminIds !== [], 'an administrator is required');
$adminId = (int) $adminIds[0];

$authRequest = $request('10');
wp_set_current_user(0);
$anonymous = $routes->authorize($authRequest);
$assert(is_wp_error($anonymous) && $anonymous->get_error_data()['status'] === 401, 'anonymous user gets 401');

wp_set_current_user($adminId);
$deniedCapability = static function (array $allCaps): array {
    $allCaps['manage_options'] = false;
    return $allCaps;
};
add_filter('user_has_cap', $deniedCapability, PHP_INT_MAX, 1);
$forbidden = $routes->authorize($authRequest);
remove_filter('user_has_cap', $deniedCapability, PHP_INT_MAX);
$assert(is_wp_error($forbidden) && $forbidden->get_error_data()['status'] === 403, 'missing capability gets 403');

$missingNonce = $routes->authorize($authRequest);
$assert(is_wp_error($missingNonce) && $missingNonce->get_error_data()['status'] === 401, 'missing nonce gets 401');
$authRequest->set_header('X-WP-Nonce', 'invalid');
$invalidNonce = $routes->authorize($authRequest);
$assert(is_wp_error($invalidNonce) && $invalidNonce->get_error_data()['status'] === 403, 'invalid nonce gets 403');
$authRequest->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
$assert($routes->authorize($authRequest) === true, 'administrator with valid nonce is authorized');

$beforePermissions = $repository->queryCount;
wp_set_current_user(0);
$anonymousRequest = $request('10');
$anonymousDispatch = rest_do_request($anonymousRequest);
$anonymousDispatch = apply_filters(
    'rest_post_dispatch',
    $anonymousDispatch,
    rest_get_server(),
    $anonymousRequest
);
$assert($anonymousDispatch->get_status() === 401, 'dispatched anonymous request gets 401');
$assert($header($anonymousDispatch) === 'private, no-store', 'dispatched 401 is not cached');
wp_set_current_user($adminId);
$missingNonceRequest = $request('10');
$missingNonceDispatch = rest_do_request($missingNonceRequest);
$missingNonceDispatch = apply_filters(
    'rest_post_dispatch',
    $missingNonceDispatch,
    rest_get_server(),
    $missingNonceRequest
);
$assert($missingNonceDispatch->get_status() === 401, 'dispatched missing nonce gets 401');
$assert($header($missingNonceDispatch) === 'private, no-store', 'dispatched missing-nonce 401 is not cached');
$invalidNonceRequest = $request('10');
$invalidNonceRequest->set_header('X-WP-Nonce', 'invalid');
$invalidNonceDispatch = rest_do_request($invalidNonceRequest);
$invalidNonceDispatch = apply_filters(
    'rest_post_dispatch',
    $invalidNonceDispatch,
    rest_get_server(),
    $invalidNonceRequest
);
$assert($invalidNonceDispatch->get_status() === 403, 'dispatched invalid nonce gets 403');
$assert($header($invalidNonceDispatch) === 'private, no-store', 'dispatched 403 is not cached');
$assert($repository->queryCount === $beforePermissions, 'permission failures do not invoke service');

$beforeSuccess = $repository->queryCount;
$success = $routes->show($request('10'));
$assert($success->get_status() === 200, 'valid detail gets 200');
$assert($repository->queryCount - $beforeSuccess === 3, 'one service detail invocation uses three operations');
$assert($header($success) === 'private, no-store', 'success is not cached');
$detail = $success->get_data();
$assert($detail['identity']['id'] === 10, 'validated ID is delivered to the service');
$assert($detail['customer'] === ['relationship_status' => 'linked'], 'customer relation contract is preserved');
$assert(! array_key_exists('customer_id', $detail), 'customer ID is not exposed');
$assert(! array_key_exists('public_id', $detail['payment']['session']), 'payment session public ID is not exposed');
$detailJson = wp_json_encode($detail);
foreach (['customer_id', 'user_id', 'user_login', 'user_email', 'email', 'phone', 'address', 'billing', 'token', 'provider_payload'] as $privateField) {
    $assert(! str_contains((string) $detailJson, $privateField), 'detail excludes private field: ' . $privateField);
}
$assert(array_column($detail['lines'], 'id') === [110], 'line order is preserved');
$timeline = $detail['operational']['timeline'];
$timelineCopy = $timeline;
usort($timelineCopy, static fn (array $left, array $right): int => $left['sequence'] <=> $right['sequence']);
$assert($timeline === $timelineCopy, 'timeline contractual order is preserved');
$assert($detail['operational']['allowed_actions'] === ['view'], 'view remains allowed');
$assert($detail['operational']['mutable_actions'] === [], 'mutable actions remain empty');

foreach (['0', '-1', '1.5', '1e3', ' 10', '10 ', '10x', '+10', '01', '999999999999999999999999'] as $invalidId) {
    $beforeInvalid = $repository->queryCount;
    $invalid = $routes->show($request($invalidId));
    $assert($invalid->get_status() === 422, 'invalid ID is rejected: ' . $invalidId);
    $assert($repository->queryCount === $beforeInvalid, 'invalid ID does not invoke service: ' . $invalidId);
    $assert($header($invalid) === 'private, no-store', 'invalid ID is not cached: ' . $invalidId);
}
$arrayId = $request('10');
$arrayId->set_url_params(['id' => ['10']]);
$beforeArray = $repository->queryCount;
$arrayResponse = $routes->show($arrayId);
$assert($arrayResponse->get_status() === 422, 'array ID is rejected');
$assert($repository->queryCount === $beforeArray, 'array ID does not invoke service');

foreach ([['return_search' => '10'], ['unknown' => 'value']] as $query) {
    $beforeQuery = $repository->queryCount;
    $invalidQuery = $routes->show($request('10', $query));
    $assert($invalidQuery->get_status() === 422, 'query parameters are rejected');
    $assert($repository->queryCount === $beforeQuery, 'query parameters do not invoke service');
}
$beforeBody = $repository->queryCount;
$bodyResponse = $routes->show($request('10', [], '{}'));
$assert($bodyResponse->get_status() === 422, 'functional body is rejected');
$assert($repository->queryCount === $beforeBody, 'functional body does not invoke service');

$missingRepository = new InstrumentedOrderAdminReadRepository([], []);
$missingResponse = $makeRoutes($missingRepository)->show($request('404'));
$assert($missingResponse->get_status() === 404, 'missing Order gets 404');
$assert($missingResponse->get_data() === ['error' => ['code' => 'order_not_found']], 'missing Order error is uniform');
$assert($header($missingResponse) === 'private, no-store', '404 is not cached');

$failingRepository = new InstrumentedOrderAdminReadRepository([$base], [10 => $bundle]);
$failingRepository->failure = 'detail';
$failureResponse = $makeRoutes($failingRepository)->show($request('10'));
$assert($failureResponse->get_status() === 500, 'persistence failure gets 500');
$assert($failureResponse->get_data() === ['error' => ['code' => 'orders_admin_detail_read_failed']], '500 error is safe');
$assert($header($failureResponse) === 'private, no-store', '500 is not cached');
$failureJson = wp_json_encode($failureResponse->get_data());
foreach (['SQL', 'InternalClass', '/private/', 'secret', 'wp_veciahorra_orders'] as $internal) {
    $assert(! str_contains((string) $failureJson, $internal), '500 excludes internal detail: ' . $internal);
}

$filterRequest = $request('10');
foreach ([401, 403] as $status) {
    $permissionResponse = new WP_REST_Response(['code' => 'permission_error'], $status);
    $filtered = $routes->protectDetailResponse($permissionResponse, null, $filterRequest);
    $assert($filtered === $permissionResponse, 'permission response identity is preserved');
    $assert($filtered->get_status() === $status, 'permission status is preserved');
    $assert($header($filtered) === 'private, no-store', 'permission error is not cached');
}
$foreign = new WP_REST_Response(['ok' => true], 200);
$foreignRequest = new WP_REST_Request('GET', '/veciahorra/v1/orders/admin');
$routes->protectDetailResponse($foreign, null, $foreignRequest);
$assert($header($foreign) === '', 'list route is not modified by detail filter');
foreach ([
    '/veciahorra/v1/orders/10/admin/extra',
    '/veciahorra/v1/orders/10',
    '/veciahorra/v1/other/10/admin',
] as $foreignRoute) {
    $foreign = new WP_REST_Response(['ok' => true], 200);
    $foreignRequest = new WP_REST_Request('GET', $foreignRoute);
    $routes->protectDetailResponse($foreign, null, $foreignRequest);
    $assert($header($foreign) === '', 'similar route is not modified: ' . $foreignRoute);
}

echo "PASS order-admin-detail-rest-test assertions={$assertions} detail_operations=3\n";
