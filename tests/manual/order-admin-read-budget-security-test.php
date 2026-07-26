<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Domain\Operational\InvariantCatalog;
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
$makeService = static fn (InstrumentedOrderAdminReadRepository $repository): OrderAdminReadService =>
    new OrderAdminReadService(
        $repository,
        new OrderOperationalFactsAssembler(),
        new OrderOperationalStateResolver(),
        '2026-07-26T16:00:00Z'
    );

$rows = [];
$bundles = [];
for ($id = 1; $id <= 20; ++$id) {
    $rows[] = OrderAdminReadFixture::base($id);
    $bundles[$id] = OrderAdminReadFixture::bundle($id);
    $bundles[$id]['order_items'][] = [
        'id' => 1000 + $id, 'order_id' => $id, 'product_id' => 21,
        'inventory_id' => 31, 'quantity' => 0, 'unit_price' => '0.00',
        'subtotal' => '0.00', 'created_at' => '2026-07-26 10:01:00',
        'updated_at' => '2026-07-26 10:01:00',
    ];
}
$repository = new InstrumentedOrderAdminReadRepository($rows, $bundles);
$result = $makeService($repository)->listOrders(new OrderAdminListQuery(perPage: 20))->toArray();
$assert(count($result['items']) === 20, 'twenty Orders resolved');
$assert($repository->queryCount === 4, 'query count does not grow per Order or line');

$detailRepository = new InstrumentedOrderAdminReadRepository([$rows[0]], [1 => $bundles[1]]);
$service = $makeService($detailRepository);
$detail = $service->getOrderDetail(1)->toArray();
$afterDetail = $detailRepository->queryCount;
$timeline = $detail['operational']['timeline'];
$inspector = $detail['inspector'];
$assert($afterDetail === 3 && $timeline !== [] && is_array($inspector), 'detail, timeline and inspector stay at three queries');
$assert($detailRepository->queryCount === $afterDetail, 'DTO access performs zero queries');

foreach (['count', 'page', 'facts', 'detail'] as $failure) {
    $failing = new InstrumentedOrderAdminReadRepository([$rows[0]], [1 => $bundles[1]]);
    $failing->failure = $failure;
    try {
        $failure === 'detail'
            ? $makeService($failing)->getOrderDetail(1)
            : $makeService($failing)->listOrders(new OrderAdminListQuery());
        $assert(false, $failure . ' must fail safely');
    } catch (OrderAdminReadException $exception) {
        $message = $exception->getMessage();
        $assert(
            ! str_contains($message, 'SQL')
            && ! str_contains($message, '/private/')
            && ! str_contains($message, 'InternalClass')
            && ! str_contains($message, 'secret'),
            $failure . ' hides internal persistence details'
        );
    }
}

$repositorySource = file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Orders/Repositories/OrderAdminReadRepository.php');
$newSources = $repositorySource
    . file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Orders/Services/OrderAdminReadService.php')
    . file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Orders/Services/OrderOperationalFactsAssembler.php');
foreach (['->insert(', '->update(', '->delete(', 'INSERT INTO', 'UPDATE ', 'DELETE FROM', 'START TRANSACTION', 'register_rest_route', 'add_action(', 'wp_nonce'] as $forbidden) {
    $assert(! str_contains($newSources, $forbidden), 'new infrastructure excludes ' . $forbidden);
}
$assert(substr_count($repositorySource, 'SELECT ') > 0, 'repository contains read queries');
$assert(! str_contains($repositorySource, 'primary_state'), 'repository does not duplicate derived primary state');
$assert(count(InvariantCatalog::codes()) === 31, 'read models reuse exact invariant catalog');

echo 'PASS order-admin-read-budget-security-test assertions=' . $assertions . PHP_EOL;
