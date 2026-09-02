<?php

declare(strict_types=1);

$GLOBALS['va_fee_test_options'] = [];
if (! function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['va_fee_test_options'][$key] ?? $default; }
    function update_option(string $key, mixed $value, bool $autoload = false): bool { $GLOBALS['va_fee_test_options'][$key] = $value; return true; }
    function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
    function wp_salt(string $scheme = 'auth'): string { return 'checkout-fees-test-salt'; }
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use VeciAhorra\Database\Builder\TableBuilder;
use VeciAhorra\Database\Schemas\CheckoutRefundSchema;
use VeciAhorra\Database\Schemas\CheckoutSchema;
use VeciAhorra\Modules\Checkout\Service\CheckoutFeeCalculator;
use VeciAhorra\Modules\Checkout\Service\CheckoutFeeConfiguration;
use VeciAhorra\Modules\Checkout\Service\CheckoutRefundPolicy;
use VeciAhorra\Modules\Payments\Service\IdempotencyService;

$count = 0;
$assert = static function (bool $value, string $message) use (&$count): void {
    $count++;
    if (! $value) throw new RuntimeException($message);
};
$throws = static function (callable $callback, string $message) use ($assert): void {
    try { $callback(); } catch (Throwable) { $assert(true, $message); return; }
    $assert(false, $message);
};

$configuration = new CheckoutFeeConfiguration();
$defaults = $configuration->current();
$assert($defaults['platform_fee_clp'] === 700, 'Default platform fee.');
$assert($defaults['delivery_fee_clp'] === 1000, 'Default delivery fee.');
$assert($defaults['delivery_minimum_subtotal_clp'] === 8000, 'Default delivery minimum.');
$saved = $configuration->save(['platform_fee_clp' => '900', 'delivery_fee_clp' => '1200', 'delivery_minimum_subtotal_clp' => '8500']);
$assert([$saved['platform_fee_clp'], $saved['delivery_fee_clp'], $saved['delivery_minimum_subtotal_clp']] === [900, 1200, 8500], 'Admin values update.');

foreach ([-1, 1.5, '1.0', '1e3', '', ' 700', '700 ', '1.000', [], new stdClass(), '010', (string) (CheckoutFeeConfiguration::MAX_CLP + 1)] as $invalid) {
    $throws(fn () => $configuration->validate(['platform_fee_clp' => $invalid, 'delivery_fee_clp' => 1000, 'delivery_minimum_subtotal_clp' => 8000]), 'Invalid canonical CLP rejected.');
}

$GLOBALS['va_fee_test_options'] = [];
$calculator = new CheckoutFeeCalculator($configuration);
$pickup = $calculator->calculate(8000, 'pickup', true);
$delivery = $calculator->calculate(8000, 'delivery', true);
$assert($pickup['product_subtotal'] === '8000.00' && $pickup['platform_fee'] === '700.00' && $pickup['delivery_fee'] === '0.00' && $pickup['total'] === '8700.00', 'Pickup formula.');
$assert($delivery['product_subtotal'] === '8000.00' && $delivery['platform_fee'] === '700.00' && $delivery['delivery_fee'] === '1000.00' && $delivery['total'] === '9700.00', 'Delivery formula.');
$throws(fn () => $calculator->calculate(7999, 'delivery', true), '7999 does not qualify.');
$throws(fn () => $calculator->calculate(8000, 'delivery', false), 'Non-deliverable cart rejected.');
$assert($calculator->calculate(16000, 'pickup', true)['platform_fee'] === '700.00', 'Platform fee once for multi-store subtotal.');
$assert($calculator->calculate(16000, 'delivery', true)['delivery_fee'] === '1000.00', 'Delivery fee once for multi-store subtotal.');
$frozen = $calculator->calculate(8000, 'pickup', true);
$configuration->save(['platform_fee_clp' => 999, 'delivery_fee_clp' => 1999, 'delivery_minimum_subtotal_clp' => 9000]);
$assert($frozen['total'] === '8700.00' && $calculator->calculate(8000, 'pickup', true)['total'] === '8999.00', 'Configuration change affects only non-materialized calculations.');

$refunds = new CheckoutRefundPolicy();
$partial = $refunds->calculate(8000, 700, 1000, 0, 0, 0, 3000);
$assert($partial['total_refund'] === 3000 && $partial['platform_fee_refund'] === 0 && $partial['delivery_fee_refund'] === 0, 'Partial excludes fees.');
$final = $refunds->calculate(8000, 700, 1000, 3000, 0, 0, 5000);
$assert($final['total_refund'] === 6700 && $final['platform_fee_refund'] === 700 && $final['delivery_fee_refund'] === 1000, 'Final cumulative refund includes fees once.');
$pickupFull = $refunds->calculate(8000, 700, 0, 0, 0, 0, 8000);
$assert($pickupFull['total_refund'] === 8700, 'Full pickup refund includes platform only.');
$throws(fn () => $refunds->calculate(8000, 700, 1000, 8000, 700, 1000, 1), 'Duplicate full refund rejected.');

$checkoutBuilder = TableBuilder::make('wp_va_checkouts');
(new CheckoutSchema())->define($checkoutBuilder);
$checkoutSql = $checkoutBuilder->build('');
foreach (['product_subtotal', 'platform_fee', 'delivery_fee', 'fee_policy_version'] as $column) {
    $assert(str_contains($checkoutSql, $column), "Checkout schema contains {$column}.");
}
$refundBuilder = TableBuilder::make('wp_va_checkout_refunds');
(new CheckoutRefundSchema())->define($refundBuilder);
$refundSql = $refundBuilder->build('');
$assert(str_contains($refundSql, 'checkout_refunds_key_unique') && str_contains($refundSql, 'platform_fee_refund'), 'Refund durable uniqueness.');

$idempotency = new IdempotencyService();
$owner = $idempotency->owner(['user_id' => 42]);
$base = $idempotency->fingerprint('chk_' . str_repeat('A', 43), $owner, 'CLP', '9700.00', [2, 1], 'delivery', ['product_subtotal' => '8000.00', 'platform_fee' => '700.00', 'delivery_fee' => '1000.00']);
$same = $idempotency->fingerprint('chk_' . str_repeat('A', 43), $owner, 'CLP', '9700.00', [1, 2], 'delivery', ['product_subtotal' => '8000.00', 'platform_fee' => '700.00', 'delivery_fee' => '1000.00']);
$changed = $idempotency->fingerprint('chk_' . str_repeat('A', 43), $owner, 'CLP', '8700.00', [1, 2], 'pickup', ['product_subtotal' => '8000.00', 'platform_fee' => '700.00', 'delivery_fee' => '0.00']);
$assert(hash_equals($base, $same), 'Same financial identity reuses fingerprint.');
$assert(! hash_equals($base, $changed), 'Method and fees alter fingerprint.');
$legacy = $idempotency->fingerprint('chk_' . str_repeat('B', 43), $owner, 'CLP', '8000.00', [1], 'pickup');
$legacyExpected = hash('sha256', (string) wp_json_encode([
    'operation' => 'payment_session.start.v1', 'checkout_public_id' => 'chk_' . str_repeat('B', 43),
    'owner' => ['type' => 'user', 'stable_id' => '42'], 'currency' => 'CLP', 'total_amount' => '8000.00',
    'orders' => [1], 'fulfillment_method' => 'pickup', 'gateway' => 'webpay_plus',
], JSON_UNESCAPED_SLASHES));
$assert(hash_equals($legacyExpected, $legacy), 'Historical payment-session fingerprint remains v1 compatible.');

echo "PASS checkout-fees-contract assertions={$count} external_calls=0 floats_for_money=0\n";
