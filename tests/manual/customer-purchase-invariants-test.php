<?php

declare(strict_types=1);

use VeciAhorra\Modules\CustomerPanel\Query\CustomerPurchaseQuery;
use VeciAhorra\Modules\CustomerPanel\Service\CustomerPanelService;
use VeciAhorra\Modules\CustomerPanel\Service\CustomerPurchaseStatusResolver;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertPurchaseInvariant(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$service = new CustomerPanelService(new CustomerPurchaseQuery(), new CustomerPurchaseStatusResolver());
$inconsistent = new ReflectionMethod($service, 'inconsistent');
$checkout = [
    'id' => 100, 'public_id' => 'chk_' . str_repeat('A', 43),
    'status' => 'payment_started', 'fulfillment_method' => 'delivery',
    'currency' => 'CLP', 'total_amount' => '3000.00',
];
$orders = [
    ['id' => 1, 'customer_id' => 50, 'minimarket_id' => 501, 'status' => 'paid', 'total' => '1000.00', 'item_total' => '1000.00'],
    ['id' => 2, 'customer_id' => 50, 'minimarket_id' => 502, 'status' => 'paid', 'total' => '2000.00', 'item_total' => '2000.00'],
];
$payment = [
    'id' => 10, 'customer_id' => 50, 'checkout_id' => 100, 'status' => 'paid',
    'currency' => 'CLP', 'amount' => '3000.00', 'reconciliation_id' => 30,
];
$deliveries = [
    ['order_id' => 1, 'customer_id' => 50, 'minimarket_id' => 501, 'status' => 'assigned'],
    ['order_id' => 2, 'customer_id' => 50, 'minimarket_id' => 502, 'status' => 'pending'],
];
$attempt = [
    'reconciliation_id' => 30, 'reconciliation_status' => 'completed',
    'reconciliation_origin' => 'veciahorra_checkout',
    'reconciliation_resource_id' => $checkout['public_id'],
    'session_public_id' => 'ps_test', 'origin_attempt_id' => 'ps_test',
    'reconciliation_attempt_id' => 'ps_test',
    'financial_status' => 'approved', 'return_amount_clp' => 3000, 'return_currency' => 'CLP',
    'origin' => 'veciahorra_checkout', 'origin_resource_id' => $checkout['public_id'],
    'origin_currency' => 'CLP', 'origin_amount_clp' => 3000,
    'business_id' => 20, 'business_status' => 'completed', 'business_payment_id' => 10,
    'business_fulfillment_method' => 'delivery',
    'delivery_completion_status' => 'completed',
    'fulfillment_completion_status' => 'completed',
];
$invoke = static fn (
    ?array $checkoutValue = null,
    ?array $ordersValue = null,
    ?array $paymentValue = null,
    ?array $deliveriesValue = null,
    ?array $attemptValue = null,
    ?array $paymentOrders = null,
    ?array $businessOrders = null
): bool => $inconsistent->invoke(
    $service, $checkoutValue ?? $checkout, $ordersValue ?? $orders, [], $paymentValue ?? $payment,
    $deliveriesValue ?? $deliveries, $attemptValue ?? $attempt, 50,
    $paymentOrders ?? [10 => [1, 2]], $businessOrders ?? [20 => [1, 2]]
);

assertPurchaseInvariant(! $invoke(), 'La proyeccion coherente fue rechazada.');
assertPurchaseInvariant($invoke(paymentValue: ['_cardinality_invalid' => true]), 'Multiples Payments no degradan a revision.');
assertPurchaseInvariant($invoke(paymentOrders: [10 => [1]]), 'Payment parcial no degrada a revision.');
assertPurchaseInvariant($invoke(paymentOrders: [10 => [1, 2, 3]]), 'Payment con Order adicional no degrada a revision.');
assertPurchaseInvariant($invoke(attemptValue: array_replace($attempt, ['_multiple_completed_reconciliations' => true])), 'Multiples conciliaciones completed no degradan a revision.');
assertPurchaseInvariant($invoke(deliveriesValue: [$deliveries[0], $deliveries[0]]), 'Delivery duplicada no degrada a revision.');
assertPurchaseInvariant($invoke(deliveriesValue: [$deliveries[0]]), 'Delivery parcial no degrada a revision.');
assertPurchaseInvariant($invoke(deliveriesValue: [array_replace($deliveries[0], ['order_id' => 99])]), 'Delivery ajena no degrada a revision.');
assertPurchaseInvariant($invoke(deliveriesValue: [array_replace($deliveries[0], ['customer_id' => 99]), $deliveries[1]]), 'Delivery de otro cliente no degrada a revision.');
assertPurchaseInvariant($invoke(checkoutValue: array_replace($checkout, ['fulfillment_method' => 'pickup'])), 'Pickup con Deliveries no degrada a revision.');
assertPurchaseInvariant($invoke(attemptValue: array_replace($attempt, ['delivery_completion_status' => 'not_required'])), 'not_required con delivery no degrada a revision.');

$add = new ReflectionMethod($service, 'add');
assertPurchaseInvariant(
    $add->invoke($service, '99999999.99', '0.01') === '100000000.00',
    'La suma monetaria no es decimal exacta.'
);

$timeline = new ReflectionMethod($service, 'timeline');
$same = '2026-07-16 12:00:00';
$events = $timeline->invoke($service, [
    'checkout' => ['created_at' => $same, 'fulfillment_method' => 'delivery'],
    'payment' => ['paid_at' => $same],
    'attempt' => ['business_completed_at' => $same],
    'deliveries' => [['created_at' => $same]],
]);
$codes = array_map(static fn ($event): string => $event->code, $events);
assertPurchaseInvariant(
    $codes === ['checkout_created', 'delivery_created', 'orders_materialized', 'payment_confirmed'],
    'El timeline no desempata deterministamente por codigo.'
);

echo "PASS customer-purchase-invariants-test\n";
