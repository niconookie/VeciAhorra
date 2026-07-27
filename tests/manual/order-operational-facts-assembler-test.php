<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalStateResolver;
use VeciAhorra\Modules\Orders\Services\OrderOperationalFactsAssembler;
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
$assembler = new OrderOperationalFactsAssembler();
$resolver = new OrderOperationalStateResolver();
$observedAt = '2026-07-26T16:00:00Z';
$base = OrderAdminReadFixture::base();
$bundle = OrderAdminReadFixture::bundle();
$originalBase = $base;
$originalBundle = $bundle;
$facts = $assembler->assemble($base, $bundle, $observedAt);
$all = $facts->all();

$assert($all['order']['id'] === 10, 'Order ID normalized to int');
$assert($all['order']['total'] === '1000.00', 'Order money canonical');
$assert($all['order']['created_at'] === '2026-07-26T10:01:00Z', 'timestamp normalized to UTC');
$assert($all['order_items'][0]['unit_price'] === '500.00', 'line money canonical');
$assert($all['financial_evidence']['validated'] === true, 'financial validation normalized to bool');
$assert($all['payment_session']['status'] === 'confirmed', 'latest session selected');
$assert($all['deliveries'] === [], 'Pickup has no Delivery');
$assert($base === $originalBase && $bundle === $originalBundle, 'assembler does not mutate rows');

$pickup = $resolver->resolve($facts)->toArray();
$assert($pickup['primary_state'] === 'completed', 'Pickup completed with paid Order');
$assert($pickup['allowed_actions'] === ['view'] && $pickup['mutable_actions'] === [], 'read-only actions');

$deliveryBase = OrderAdminReadFixture::base(11, 'delivery');
$deliveryBundle = OrderAdminReadFixture::bundle(11, 'delivery');
$delivery = $resolver->resolve($assembler->assemble($deliveryBase, $deliveryBundle, $observedAt))->toArray();
$assert($delivery['primary_state'] === 'completed', 'Delivery completed');
$assert($delivery['dimensions']['delivery'] === 'delivered', 'Delivery dimension');

$minimal = $base;
$minimal['order_status'] = 'reserved';
$minimal['checkout_id'] = null;
$minimal['checkout_order_link_id'] = null;
$minimal['resolved_store_id'] = null;
$minimalFacts = $assembler->assemble($minimal, ['order_items' => $bundle['order_items']], $observedAt)->all();
$assert($minimalFacts['checkout'] === null, 'absent Checkout remains null');
$assert($minimalFacts['payment_session'] === null, 'absent PaymentSession remains null');
$assert($minimalFacts['order']['current_store_exists'] === false, 'missing current Store represented');

$unknown = $bundle;
$unknown['payment_sessions'][0]['status'] = 'future_state';
$unknownResolution = $resolver->resolve($assembler->assemble($base, $unknown, $observedAt))->toArray();
$assert($unknownResolution['dimensions']['payment_session'] === 'unknown', 'unknown state is not defaulted');

$multiple = $bundle;
$multiple['order_items'][] = [
    'id' => 999, 'order_id' => 10, 'product_id' => 21, 'inventory_id' => 31,
    'quantity' => 1, 'unit_price' => '0.00', 'subtotal' => '0.00',
    'created_at' => '2026-07-26 10:01:00', 'updated_at' => '2026-07-26 10:01:00',
];
$multiple['reservations'][] = [
    'id' => 998, 'order_id' => 10, 'product_id' => 21, 'inventory_id' => 31,
    'minimarket_id' => 3, 'quantity' => 1, 'status' => 'consumed',
    'reserved_at' => '2026-07-26 10:02:00', 'expires_at' => '2026-07-26 10:30:00',
    'released_at' => null, 'updated_at' => '2026-07-26 10:08:00',
];
$multipleFacts = $assembler->assemble($base, $multiple, $observedAt)->all();
$assert(count($multipleFacts['order_items']) === 2 && count($multipleFacts['reservations']) === 2, 'multiple rows preserved');

$attempts = $bundle;
$attempts['payment_sessions'][] = [
    'id' => 9999, 'checkout_id' => 50, 'status' => 'future_state',
    'created_at' => '2026-07-26 12:00:00', 'updated_at' => '2026-07-26 12:00:00',
];
$attempts['payments'][] = [
    'id' => 9998, 'checkout_id' => 50, 'payment_session_id' => 9999,
    'status' => 'pending', 'amount' => '1.00', 'currency' => 'CLP',
    'created_at' => '2026-07-26 12:00:00', 'updated_at' => '2026-07-26 12:00:00',
];
$attemptFacts = $assembler->assemble($base, $attempts, $observedAt)->all();
$assert($attemptFacts['payment']['id'] === 100, 'PaymentOrder selects the durable Payment');
$assert($attemptFacts['payment_session']['id'] === 70, 'Payment relation selects the durable session');

$reordered = $bundle;
foreach ($reordered as &$rows) {
    if (is_array($rows) && array_is_list($rows)) {
        $rows = array_reverse($rows);
    }
}
unset($rows);
$firstVersion = $resolver->resolve($assembler->assemble($base, $bundle, $observedAt))->operationalVersion;
$reorderedVersion = $resolver->resolve($assembler->assemble($base, $reordered, $observedAt))->operationalVersion;
$assert($firstVersion === $reorderedVersion, 'reordered SQL rows preserve operational version');

$equivalentBase = $base;
$equivalentBase['total'] = '01000.0';
$equivalentBase['order_updated_at'] = '2026-07-26T06:09:00-04:00';
$equivalentBundle = $bundle;
$equivalentBundle['order_items'][0]['unit_price'] = '0500.00';
$equivalentVersion = $resolver->resolve(
    $assembler->assemble($equivalentBase, $equivalentBundle, $observedAt)
)->operationalVersion;
$assert($equivalentVersion === $firstVersion, 'equivalent money and timestamp representations normalize equally');

$partial = $bundle;
$partial['read_failures'] = [['scope' => 'optional', 'code' => 'tracking_unavailable']];
$assert(
    $resolver->resolve($assembler->assemble($base, $partial, $observedAt))->consistencyState === 'degraded',
    'optional failure remains degraded'
);

$unsafeBundle = $bundle;
$unsafeBundle['payment_sessions'][0]['token'] = 'secret-token';
$unsafeBundle['payment_sessions'][0]['provider_payload'] = ['private' => true];
$unsafeBundle['deliveries'][] = ['id' => 1, 'customer_id' => 7, 'latitude' => '-33.1'];
$unsafeBundle['delivery_tracking'][] = ['id' => 2, 'event' => 'assigned', 'latitude' => '-33.1', 'created_at' => '2026-07-26 10:12:00'];
$safeDetail = $assembler->safeDetail($base, $unsafeBundle);
$assert($safeDetail['customer'] === ['relationship_status' => 'linked'], 'confirmed customer relation is linked');
$unknownCustomerBase = $base;
$unknownCustomerBase['customer_id'] = null;
$unknownCustomer = $assembler->safeDetail($unknownCustomerBase, $unsafeBundle);
$assert($unknownCustomer['customer'] === ['relationship_status' => 'unknown'], 'unconfirmed customer relation is unknown');
foreach ([$safeDetail, $unknownCustomer] as $customerDetail) {
    $assert(
        in_array($customerDetail['customer']['relationship_status'], ['linked', 'unknown'], true),
        'customer relation belongs to the closed enum'
    );
}
$assert(! array_key_exists('public_id', $safeDetail['payment']['session']), 'safe payment session excludes public ID');

$unorderedBundle = $bundle;
$unorderedBundle['order_items'][] = [
    'id' => 1, 'order_id' => 10, 'product_id' => 22, 'inventory_id' => 32,
    'quantity' => 1, 'unit_price' => '1.00', 'subtotal' => '1.00',
];
$unorderedBundle['order_items'] = array_reverse($unorderedBundle['order_items']);
$orderedDetail = $assembler->safeDetail($base, $unorderedBundle);
$assert(array_column($orderedDetail['lines'], 'id') === [1, 110], 'safe detail lines use stable ID order');

$safe = json_encode($safeDetail, JSON_THROW_ON_ERROR);
foreach (['customer_id', 'user_id', 'email', 'phone', 'address', 'token', 'payload', 'latitude', 'longitude', 'SQL ', 'InternalClass'] as $forbidden) {
    $assert(! str_contains($safe, $forbidden), 'safe detail excludes ' . $forbidden);
}
$assert(! str_contains($safe, 'session-safe-10'), 'safe detail excludes payment session public ID value');

echo 'PASS order-operational-facts-assembler-test assertions=' . $assertions . PHP_EOL;
