<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalStateResolver;
use VeciAhorra\Modules\Orders\DTO\Admin\OrderAdminListQuery;
use VeciAhorra\Modules\Orders\Exceptions\OrderAdminReadException;
use VeciAhorra\Modules\Orders\Services\OrderAdminReadService;
use VeciAhorra\Modules\Orders\Services\OrderOperationalFactsAssembler;
use VeciAhorra\Tests\Manual\Support\InstrumentedOrderAdminReadRepository;
use VeciAhorra\Tests\Manual\Support\OrderAdminReadFixture;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/support/OrderAdminReadTestSupport.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$service = static function (InstrumentedOrderAdminReadRepository $repository): OrderAdminReadService {
    return new OrderAdminReadService(
        $repository,
        new OrderOperationalFactsAssembler(),
        new OrderOperationalStateResolver(),
        '2026-07-26T16:00:00Z'
    );
};

$emptyRepository = new InstrumentedOrderAdminReadRepository([], []);
$empty = $service($emptyRepository)->listOrders(new OrderAdminListQuery())->toArray();
$assert($empty['items'] === [] && $empty['pagination']['total'] === 0, 'empty list');
$assert($emptyRepository->queryCount === 2, 'empty list uses exactly two queries');

$rows = [OrderAdminReadFixture::base(10), OrderAdminReadFixture::base(11, 'delivery')];
$rows[1]['order_status'] = 'delivered';
$rows[1]['order_created_at'] = '2026-07-26 11:01:00';
$bundles = [10 => OrderAdminReadFixture::bundle(10), 11 => OrderAdminReadFixture::bundle(11, 'delivery')];
$repository = new InstrumentedOrderAdminReadRepository($rows, $bundles);
$listed = $service($repository)->listOrders(new OrderAdminListQuery(order: 'newest'))->toArray();
$assert(count($listed['items']) === 2 && $listed['items'][0]['id'] === 11, 'stable newest ordering');
$assert($repository->queryCount === 4, 'non-empty list uses exactly four queries');
foreach ($listed['items'] as $item) {
    $assert($item['primary_state'] === 'completed', 'list uses certified resolver');
    $assert($item['allowed_actions'] === ['view'] && $item['mutable_actions'] === [], 'list is read-only');
    $assert(isset($item['operational_version']), 'list exposes operational version');
}

$searchRepository = new InstrumentedOrderAdminReadRepository($rows, $bundles);
$searched = $service($searchRepository)->listOrders(new OrderAdminListQuery(search: 'checkout:51'))->toArray();
$assert(count($searched['items']) === 1 && $searched['items'][0]['id'] === 11, 'Checkout search');
$idRepository = new InstrumentedOrderAdminReadRepository($rows, $bundles);
$assert($service($idRepository)->listOrders(new OrderAdminListQuery(search: '10'))->items[0]->toArray()['id'] === 10, 'Order ID search');
$storeRepository = new InstrumentedOrderAdminReadRepository($rows, $bundles);
$assert(count($service($storeRepository)->listOrders(new OrderAdminListQuery(search: 'Almacen'))->items) === 2, 'Store search');
$filterRepository = new InstrumentedOrderAdminReadRepository($rows, $bundles);
$assert(count($service($filterRepository)->listOrders(new OrderAdminListQuery(orderStatus: 'delivered'))->items) === 1, 'persisted filter');
$dateRepository = new InstrumentedOrderAdminReadRepository($rows, $bundles);
$assert(count($service($dateRepository)->listOrders(new OrderAdminListQuery(createdFrom: '2026-07-26 11:00:00'))->items) === 1, 'creation range filter');

$pageRows = [];
$pageBundles = [];
for ($id = 1; $id <= 21; ++$id) {
    $pageRows[] = OrderAdminReadFixture::base($id);
    $pageBundles[$id] = OrderAdminReadFixture::bundle($id);
}
$pageRepository = new InstrumentedOrderAdminReadRepository($pageRows, $pageBundles);
$pageTwo = $service($pageRepository)->listOrders(new OrderAdminListQuery(page: 2, perPage: 20, order: 'oldest'))->toArray();
$assert(count($pageTwo['items']) === 1 && $pageTwo['pagination']['total_pages'] === 2, 'canonical page pagination');

foreach ([
    static fn (): OrderAdminListQuery => new OrderAdminListQuery(page: 0),
    static fn (): OrderAdminListQuery => new OrderAdminListQuery(perPage: 10),
    static fn (): OrderAdminListQuery => new OrderAdminListQuery(order: 'raw_sql'),
    static fn (): OrderAdminListQuery => new OrderAdminListQuery(orderStatus: 'invented'),
    static fn (): OrderAdminListQuery => new OrderAdminListQuery(search: '0'),
    static fn (): OrderAdminListQuery => new OrderAdminListQuery(search: '-1'),
    static fn (): OrderAdminListQuery => new OrderAdminListQuery(search: '1.5'),
    static fn (): OrderAdminListQuery => new OrderAdminListQuery(search: '1e3'),
    static fn (): OrderAdminListQuery => new OrderAdminListQuery(search: ' 10 '),
    static fn (): OrderAdminListQuery => new OrderAdminListQuery(search: 'checkout:0'),
] as $invalid) {
    try {
        $invalid();
        $assert(false, 'invalid query rejected');
    } catch (InvalidArgumentException) {
        $assert(true, 'invalid query rejected');
    }
}

$detailRepository = new InstrumentedOrderAdminReadRepository($rows, $bundles);
$detail = $service($detailRepository)->getOrderDetail(10)->toArray();
$assert($detailRepository->queryCount === 3, 'detail uses exactly three queries');
$assert($detail['identity']['id'] === 10, 'detail identity');
$assert($detail['checkout_order']['order_id'] === 10, 'detail exposes CheckoutOrder relation');
$assert($detail['lines'][0]['product_name_snapshot'] === null, 'missing historical name is explicit');
$listItem = $listed['items'][1];
$assert($detail['operational']['primary_state'] === $listItem['primary_state'], 'list/detail primary equivalent');
foreach (['commercial', 'financial', 'reservations', 'processing', 'fulfillment', 'delivery', 'payment_session'] as $dimension) {
    $assert($detail['operational']['dimensions'][$dimension] === $listItem['dimensions'][$dimension], 'list/detail ' . $dimension . ' equivalent');
}
$assert($detail['operational']['consistency']['classification'] === $listItem['consistency_state'], 'list/detail consistency equivalent');
$assert(count($detail['operational']['consistency']['warnings']) === $listItem['warning_count'], 'list/detail warnings equivalent');
$assert(count($detail['operational']['consistency']['blockers']) === $listItem['blocker_count'], 'list/detail blockers equivalent');
$assert($detail['operational']['requires_attention'] === $listItem['requires_attention'], 'list/detail attention equivalent');
$assert($detail['operational']['operational_version'] === $listItem['operational_version'], 'list/detail version equivalent');
$assert($detail['operational']['allowed_actions'] === $listItem['allowed_actions'], 'list/detail allowed actions equivalent');
$assert($detail['operational']['mutable_actions'] === $listItem['mutable_actions'], 'list/detail mutable actions equivalent');
$assert($detail['operational']['timeline'] !== [], 'detail reuses resolver timeline');
$assert(isset($detail['inspector']['by_dimension']), 'inspector uses existing findings');
$assert($detail['operational']['allowed_actions'] === ['view'] && $detail['operational']['mutable_actions'] === [], 'detail is read-only');
$assert($detail['navigation']['product_ids'] === [20] && $detail['navigation']['inventory_ids'] === [30], 'historical navigation IDs');

$serialized = json_encode([$listed, $detail], JSON_THROW_ON_ERROR);
foreach (['customer_id', 'user_id', 'email', 'phone', 'address', 'token', 'payload', 'latitude', 'longitude', 'provider_reference', 'financial_fingerprint'] as $forbidden) {
    $assert(! str_contains($serialized, $forbidden), 'read models exclude ' . $forbidden);
}

$poisonedBase = OrderAdminReadFixture::base(12);
$poisonedBase['email'] = 'leak@example.test';
$poisonedBase['address'] = 'Secret Street 123';
$poisonedBundle = OrderAdminReadFixture::bundle(12);
foreach ($poisonedBundle as &$authorityRows) {
    foreach ($authorityRows as &$authorityRow) {
        $authorityRow['token'] = 'token-secret-xyz';
        $authorityRow['provider_payload'] = 'payload-secret-xyz';
        $authorityRow['internal_message'] = 'SELECT private FROM secrets';
        $authorityRow['stack_trace'] = 'C:\\private\\InternalClass.php';
        $authorityRow['latitude'] = '-33.1234567';
    }
    unset($authorityRow);
}
unset($authorityRows);
$privacyRepository = new InstrumentedOrderAdminReadRepository(
    [$poisonedBase],
    [12 => $poisonedBundle]
);
$privacyService = $service($privacyRepository);
$privacySerialized = json_encode([
    $privacyService->listOrders(new OrderAdminListQuery())->toArray(),
    $privacyService->getOrderDetail(12)->toArray(),
], JSON_THROW_ON_ERROR);
foreach (['leak@example.test', 'Secret Street 123', 'token-secret-xyz', 'payload-secret-xyz', 'SELECT private', 'InternalClass.php', '-33.1234567'] as $secret) {
    $assert(! str_contains($privacySerialized, $secret), 'all DTO surfaces exclude injected secret ' . $secret);
}

$compare = static function (array $scenarioBase, array $scenarioBundle) use ($service, $assert): void {
    $id = (int) $scenarioBase['order_id'];
    $repository = new InstrumentedOrderAdminReadRepository([$scenarioBase], [$id => $scenarioBundle]);
    $readService = $service($repository);
    $list = $readService->listOrders(new OrderAdminListQuery())->toArray()['items'][0];
    $detail = $readService->getOrderDetail($id)->toArray()['operational'];
    $assert($list['primary_state'] === $detail['primary_state'], 'scenario primary equivalence');
    $assert($list['dimensions'] === $detail['dimensions'], 'scenario dimensions equivalence');
    $assert($list['consistency_state'] === $detail['consistency']['classification'], 'scenario consistency equivalence');
    $assert($list['operational_version'] === $detail['operational_version'], 'scenario version equivalence');
    $assert($list['allowed_actions'] === $detail['allowed_actions'] && $list['mutable_actions'] === $detail['mutable_actions'], 'scenario actions equivalence');
};

$reservedBase = OrderAdminReadFixture::base(20);
$reservedBase['order_status'] = 'reserved';
$reservedBundle = ['order_items' => OrderAdminReadFixture::bundle(20)['order_items'], 'reservations' => OrderAdminReadFixture::bundle(20)['reservations']];
$reservedBundle['reservations'][0]['status'] = 'active';
$compare($reservedBase, $reservedBundle);

$pendingBase = OrderAdminReadFixture::base(26);
$pendingBase['order_status'] = 'reserved';
$pendingBundle = [
    'order_items' => OrderAdminReadFixture::bundle(26)['order_items'],
    'reservations' => OrderAdminReadFixture::bundle(26)['reservations'],
    'payment_sessions' => OrderAdminReadFixture::bundle(26)['payment_sessions'],
];
$pendingBundle['reservations'][0]['status'] = 'active';
$pendingBundle['payment_sessions'][0]['status'] = 'pending';
$compare($pendingBase, $pendingBundle);

$rejectedBase = $pendingBase;
$rejectedBase['order_id'] = 27;
$rejectedBase['checkout_id'] = 67;
$rejectedBundle = [
    'order_items' => OrderAdminReadFixture::bundle(27)['order_items'],
    'reservations' => OrderAdminReadFixture::bundle(27)['reservations'],
    'payment_sessions' => OrderAdminReadFixture::bundle(27)['payment_sessions'],
    'financial_evidence' => OrderAdminReadFixture::bundle(27)['financial_evidence'],
];
$rejectedBundle['reservations'][0]['status'] = 'active';
$rejectedBundle['financial_evidence'][0]['status'] = 'rejected';
$compare($rejectedBase, $rejectedBundle);

$processingBase = OrderAdminReadFixture::base(28);
$processingBase['order_status'] = 'reserved';
$processingBundle = [
    'order_items' => OrderAdminReadFixture::bundle(28)['order_items'],
    'reservations' => OrderAdminReadFixture::bundle(28)['reservations'],
    'payment_sessions' => OrderAdminReadFixture::bundle(28)['payment_sessions'],
    'financial_evidence' => OrderAdminReadFixture::bundle(28)['financial_evidence'],
    'reconciliations' => OrderAdminReadFixture::bundle(28)['reconciliations'],
];
$processingBundle['reservations'][0]['status'] = 'active';
$processingBundle['reconciliations'][0]['status'] = 'processing';
$compare($processingBase, $processingBundle);

$expiredBase = OrderAdminReadFixture::base(21);
$expiredBase['order_status'] = 'reserved';
$expiredBase['checkout_status'] = 'expired';
$expiredBundle = ['order_items' => OrderAdminReadFixture::bundle(21)['order_items'], 'reservations' => OrderAdminReadFixture::bundle(21)['reservations']];
$expiredBundle['reservations'][0]['status'] = 'expired';
$expiredBundle['reservations'][0]['released_at'] = '2026-07-26 10:20:00';
$compare($expiredBase, $expiredBundle);

$reviewBase = OrderAdminReadFixture::base(22);
$reviewBundle = OrderAdminReadFixture::bundle(22);
$reviewBundle['reconciliations'][0]['status'] = 'manual_review';
$compare($reviewBase, $reviewBundle);

$retryBase = OrderAdminReadFixture::base(23);
$retryBundle = OrderAdminReadFixture::bundle(23);
$retryBundle['reconciliations'][0]['status'] = 'retryable';
$compare($retryBase, $retryBundle);

$failedBase = OrderAdminReadFixture::base(29);
$failedBundle = OrderAdminReadFixture::bundle(29);
$failedBundle['fulfillment_completions'][0]['status'] = 'permanent_failure';
$compare($failedBase, $failedBundle);

$deliveryBase = OrderAdminReadFixture::base(30, 'delivery');
$compare($deliveryBase, OrderAdminReadFixture::bundle(30, 'delivery'));

$historicalBase = OrderAdminReadFixture::base(31);
$historicalBase['historical_profile'] = 'legacy-paid-v1';
$historicalBundle = [
    'order_items' => OrderAdminReadFixture::bundle(31)['order_items'],
    'reservations' => OrderAdminReadFixture::bundle(31)['reservations'],
];
$compare($historicalBase, $historicalBundle);

$missingStoreBase = OrderAdminReadFixture::base(32);
$missingStoreBase['resolved_store_id'] = null;
$missingStoreBase['store_name'] = null;
$missingStoreBase['store_status'] = null;
$compare($missingStoreBase, OrderAdminReadFixture::bundle(32));

$inconsistentBase = OrderAdminReadFixture::base(24);
$inconsistentBase['order_status'] = 'future_status';
$compare($inconsistentBase, OrderAdminReadFixture::bundle(24));

$degradedBase = OrderAdminReadFixture::base(25);
$degradedBundle = OrderAdminReadFixture::bundle(25);
$degradedBundle['read_failures'] = [
    ['scope' => 'optional', 'code' => 'tracking_unavailable'],
    ['scope' => 'optional', 'code' => 'store_context_unavailable'],
];
$compare($degradedBase, $degradedBundle);

$missing = new InstrumentedOrderAdminReadRepository([], []);
try {
    $service($missing)->getOrderDetail(999);
    $assert(false, 'missing Order rejected');
} catch (OrderAdminReadException $exception) {
    $assert($exception->errorCode === 'not_found', 'missing Order safe code');
}

echo 'PASS order-admin-read-model-test assertions=' . $assertions . PHP_EOL;
