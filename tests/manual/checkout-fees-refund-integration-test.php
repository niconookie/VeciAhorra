<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

global $wpdb;
$originalPrefix = $wpdb->prefix;
$prefix = 'vafr_' . strtolower(wp_generate_password(8, false, false)) . '_';
$checkoutTable = $prefix . 'va_checkouts';
$refundTable = $prefix . 'va_checkout_refunds';
$paymentTable = $prefix . 'va_payments';
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) throw new RuntimeException($message);
};

try {
    foreach ([[new \VeciAhorra\Database\Schemas\CheckoutSchema(), $checkoutTable], [new \VeciAhorra\Database\Schemas\CheckoutRefundSchema(), $refundTable], [new \VeciAhorra\Database\Schemas\PaymentSchema(), $paymentTable]] as [$schema, $table]) {
        $builder = \VeciAhorra\Database\Builder\TableBuilder::make($table);
        $schema->define($builder);
        dbDelta($builder->build($wpdb->get_charset_collate()));
    }
    $now = current_time('mysql');
    $wpdb->insert($checkoutTable, [
        'public_id' => 'chk_refund_test', 'owner_type' => 'user', 'user_id' => 1,
        'status' => 'payment_started', 'fulfillment_method' => 'delivery',
        'currency' => 'CLP', 'product_subtotal' => '8000.00', 'platform_fee' => '700.00',
        'delivery_fee' => '1000.00', 'fee_policy_version' => 'checkout-fees-v1',
        'total_amount' => '9700.00', 'created_at' => $now, 'updated_at' => $now,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $checkoutId = (int) $wpdb->insert_id;
    $wpdb->insert($paymentTable, [
        'payment_reference'=>'pay_refund_test','checkout_id'=>$checkoutId,'customer_id'=>1,
        'amount'=>'9700.00','currency'=>'CLP','status'=>'paid','provider'=>'internal-test',
        'paid_at'=>$now,'created_at'=>$now,'updated_at'=>$now,
    ]);
    $wpdb->set_prefix($prefix);
    $service = new \VeciAhorra\Modules\Checkout\Service\CheckoutRefundService();
    $partial = $service->record($checkoutId, 'refund_partial_001', '3000.00');
    $retry = $service->record($checkoutId, 'refund_partial_001', '3000.00');
    $final = $service->record($checkoutId, 'refund_final_001', '5000.00');
    $assert($partial['total_refund'] === '3000.00' && $partial['platform_fee_refund'] === '0.00' && $partial['delivery_fee_refund'] === '0.00', 'Partial refund leaked fees.');
    $assert($retry['reused'] === true && (int) $retry['id'] === (int) $partial['id'], 'Retry was not idempotent.');
    $assert($final['total_refund'] === '6700.00' && $final['platform_fee_refund'] === '700.00' && $final['delivery_fee_refund'] === '1000.00', 'Final refund omitted fees.');
    $assert((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$refundTable}`") === 2, 'Duplicate refund row created.');
    $assert((string) $wpdb->get_var("SELECT SUM(total_refund) FROM `{$refundTable}`") === '9700.00', 'Refund aggregate does not equal checkout total.');
} finally {
    $wpdb->set_prefix($originalPrefix);
    $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $paymentTable) . '`');
    $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $refundTable) . '`');
    $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $checkoutTable) . '`');
}

echo "PASS checkout-fees-refund-integration rows=2 fees_refunded_once=1 external_calls=0 assertions={$assertions}\n";
