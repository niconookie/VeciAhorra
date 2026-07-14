<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Database\Migrations\CreateBusinessCompletionsTable;
use VeciAhorra\Database\Migrations\CreateCheckoutsTable;
use VeciAhorra\Modules\Checkout\Requests\CheckoutRequest;
use VeciAhorra\Modules\Checkout\Service\FulfillmentPolicy;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Payments\BusinessCompletion\Repository\BusinessCompletionRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

global $wpdb;
$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$deliveriesBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}deliveries");
$trackingBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}delivery_tracking");

function fulfillmentAssert(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

$policy = new FulfillmentPolicy();
foreach ([
    [7999, 'pickup', true], [7999, 'delivery', false],
    [8000, 'pickup', true], [8000, 'delivery', true],
    [9000, 'pickup', true], [9000, 'delivery', true],
] as [$amount, $method, $allowed]) {
    try {
        fulfillmentAssert($policy->authorize($method, $amount . '.00') === $method, 'Metodo no autorizado correctamente.');
        fulfillmentAssert($allowed, 'Se autorizo delivery bajo RB-CHK-001.');
    } catch (InvalidArgumentException) {
        fulfillmentAssert(! $allowed, 'Se rechazo una combinacion valida.');
    }
}

foreach ([[], ['fulfillment_method' => ''], ['fulfillment_method' => 'PICKUP'], ['fulfillment_method' => []], ['fulfillment_method' => true], ['fulfillment_method' => 1], ['fulfillment_method' => 'other']] as $invalid) {
    try {
        (new CheckoutRequest($invalid))->validated();
        throw new RuntimeException('CheckoutRequest acepto fulfillment invalido.');
    } catch (InvalidArgumentException) {
    }
}
fulfillmentAssert((new CheckoutRequest(['fulfillment_method' => 'pickup']))->validated()['fulfillment_method'] === 'pickup', 'Pickup no fue validado.');
fulfillmentAssert((new CheckoutRequest(['fulfillment_method' => 'delivery']))->validated()['fulfillment_method'] === 'delivery', 'Delivery no fue validado.');

(new CreateCheckoutsTable())->up();
(new CreateCheckoutsTable())->up();
(new CreateBusinessCompletionsTable())->up();
(new CreateBusinessCompletionsTable())->up();

$completionRepository = new BusinessCompletionRepository();
foreach ([['pickup', 'delivery'], ['delivery', 'pickup']] as $index => [$storedMethod, $requestedMethod]) {
    $wpdb->insert($prefix . 'business_completions', [
        'reconciliation_id' => 990000000 + $index,
        'idempotency_key' => hash('sha256', 'fulfillment-conflict-' . $index),
        'status' => 'processing',
        'fulfillment_method' => $storedMethod,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ]);
    $conflictId = (int) $wpdb->insert_id;
    try {
        try {
            $completionRepository->transaction(function () use (
                $completionRepository,
                $conflictId,
                $requestedMethod,
                $index
            ): void {
                $completionRepository->sealFulfillmentSnapshot(
                    $conflictId,
                    $requestedMethod,
                    [990000000 + $index],
                    current_time('mysql')
                );
            });
            throw new RuntimeException("Se permitio {$storedMethod} -> {$requestedMethod}.");
        } catch (PersistenceException) {
        }
        $stored = $wpdb->get_var($wpdb->prepare(
            "SELECT fulfillment_method FROM {$prefix}business_completions WHERE id = %d",
            $conflictId
        ));
        fulfillmentAssert($stored === $storedMethod, 'El conflicto no revirtio el metodo durable.');
        fulfillmentAssert((int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}business_completion_orders WHERE business_completion_id = %d",
            $conflictId
        )) === 0, 'El conflicto dejo relaciones parciales.');
    } finally {
        $wpdb->delete($prefix . 'business_completion_orders', ['business_completion_id' => $conflictId]);
        $wpdb->delete($prefix . 'business_completions', ['id' => $conflictId]);
    }
}

$nonce = bin2hex(random_bytes(8));
$now = current_time('mysql');
$wpdb->insert($prefix . 'checkouts', [
    'public_id' => 'chk_' . substr(hash('sha256', $nonce), 0, 43),
    'owner_type' => 'user', 'user_id' => 970001, 'session_id' => null,
    'status' => 'pending', 'currency' => 'CLP', 'total_amount' => '8000.00',
    'created_at' => $now, 'updated_at' => $now,
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
]);
$legacyId = (int) $wpdb->insert_id;
try {
    $legacy = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}checkouts WHERE id = %d", $legacyId), ARRAY_A);
    fulfillmentAssert(array_key_exists('fulfillment_method', $legacy) && $legacy['fulfillment_method'] === null, 'La migracion interpreto arbitrariamente un Checkout legacy.');
    $columns = $wpdb->get_col("SHOW COLUMNS FROM {$prefix}business_completion_orders", 0);
    fulfillmentAssert(in_array('business_completion_id', $columns, true) && in_array('order_id', $columns, true), 'Falta la relacion durable de Orders.');
    fulfillmentAssert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}deliveries") === $deliveriesBefore, 'Se crearon Deliveries.');
    fulfillmentAssert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}delivery_tracking") === $trackingBefore, 'Se creo Tracking.');
} finally {
    $wpdb->delete($prefix . 'checkouts', ['id' => $legacyId]);
}

echo "PASS durable-fulfillment-authority-test\n";
