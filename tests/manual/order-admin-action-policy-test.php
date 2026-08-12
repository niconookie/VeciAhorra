<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalFacts;
use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalStateResolver;
use VeciAhorra\Modules\Orders\Domain\Policies\OrderAdminActionPolicy;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$now = new DateTimeImmutable('2026-07-28T12:00:00Z');
$policy = new OrderAdminActionPolicy();
$resolver = new OrderOperationalStateResolver();
$base = static function (): array {
    return [
        'order' => ['id' => 10, 'customer_id' => 7, 'minimarket_id' => 3, 'status' => 'paid', 'total' => '1000.00'],
        'order_items' => [['id' => 1, 'product_id' => 2, 'inventory_id' => 3, 'quantity' => 1, 'unit_price' => '1000.00', 'subtotal' => '1000.00']],
        'checkout' => ['id' => 4, 'customer_id' => 7, 'status' => 'payment_started', 'fulfillment_method' => 'delivery', 'total_amount' => '1000.00', 'currency' => 'CLP'],
        'checkout_order_links' => [['order_id' => 10]],
        'reservations' => [['id' => 5, 'order_id' => 10, 'product_id' => 2, 'inventory_id' => 3, 'minimarket_id' => 3, 'quantity' => 1, 'status' => 'consumed']],
        'payment_attempts' => [],
        'payment_session' => ['id' => 6, 'checkout_id' => 4, 'status' => 'confirmed'],
        'financial_evidence' => ['id' => 7, 'status' => 'approved', 'validated' => true, 'amount' => 1000, 'currency' => 'CLP'],
        'reconciliation' => ['id' => 8, 'checkout_id' => 4, 'status' => 'completed', 'attempt_count' => 1, 'next_retry_at' => null],
        'payment' => ['id' => 9, 'checkout_id' => 4, 'status' => 'paid', 'amount' => '1000.00', 'currency' => 'CLP'],
        'payment_order_links' => [['order_id' => 10]],
        'business_completion' => ['id' => 11, 'reconciliation_id' => 8, 'payment_id' => 9, 'status' => 'completed', 'attempt_count' => 1, 'next_retry_at' => null, 'fulfillment_method' => 'delivery'],
        'business_order_links' => [['order_id' => 10]],
        'delivery_completion' => ['id' => 12, 'business_completion_id' => 11, 'status' => 'pending', 'attempt_count' => 1, 'next_retry_at' => null],
        'fulfillment_completion' => null,
        'deliveries' => [],
        'delivery_tracking' => [],
        'read_failures' => [],
        'historical_profile' => 'none',
    ];
};
$decide = static function (array $raw, ?DateTimeImmutable $at = null) use ($policy, $resolver, $now): array {
    $facts = new OrderOperationalFacts($raw, '2026-07-28T12:00:00Z');
    return $policy->evaluate(
        OrderAdminActionPolicy::RETRY_DURABLE_PROCESSING,
        $facts,
        $resolver->resolve($facts),
        $at ?? $now
    )->toArray();
};

$eligible = $decide($base());
$assert($eligible['action_code'] === 'retry_durable_processing', 'canonical action');
$assert($eligible['available'] && $eligible['reason_code'] === 'available', 'pending delivery completion eligible');
$assert($eligible['stage'] === 'delivery_completion', 'canonical stage');
$assert($eligible['requires_confirmation'] && $eligible['risk'] === 'medium', 'closed metadata');
try {
    $facts = new OrderOperationalFacts($base(), '2026-07-28T12:00:00Z');
    $policy->evaluate('invented', $facts, $resolver->resolve($facts), $now);
    $assert(false, 'unknown action accepted');
} catch (InvalidArgumentException) {
    $assert(true, 'unknown action rejected');
}
$reconciliation = $base();
$reconciliation['reconciliation']['status'] = 'pending';
$reconciliation['business_completion'] = null;
$reconciliation['business_order_links'] = [];
$reconciliation['delivery_completion'] = null;
$assert($decide($reconciliation)['stage'] === 'reconciliation', 'reconciliation pending is evaluated separately');
$business = $base();
$business['business_completion']['status'] = 'retryable';
$business['delivery_completion'] = null;
$assert($decide($business)['stage'] === 'business_completion', 'business completion retry is evaluated separately');

$completed = $base();
$completed['delivery_completion']['status'] = 'completed';
$completed['deliveries'] = [['id' => 20, 'order_id' => 10, 'customer_id' => 7, 'minimarket_id' => 3, 'status' => 'pending']];
$completed['fulfillment_completion'] = ['id' => 21, 'business_completion_id' => 11, 'status' => 'completed', 'attempt_count' => 1];
$assert(! $decide($completed)['available'], 'terminal pipeline blocked');
$assert(in_array($decide($completed)['reason_code'], ['historical_remediation_required', 'terminal_stage'], true), 'terminal reason closed');

$activeLease = $base();
$activeLease['delivery_completion']['status'] = 'processing';
$activeLease['delivery_completion']['lease_expires_at'] = '2026-07-28T12:01:00Z';
$assert($decide($activeLease)['reason_code'] === 'active_lease', 'active lease blocked');
$expiredLease = $activeLease;
$expiredLease['delivery_completion']['lease_expires_at'] = '2026-07-28T11:59:00Z';
$assert($decide($expiredLease)['available'], 'expired lease eligible');
$missingLease = $activeLease;
$missingLease['delivery_completion']['lease_expires_at'] = null;
$assert($decide($missingLease)['reason_code'] === 'active_lease', 'processing without expiry closes busy');

$backoff = $base();
$backoff['delivery_completion']['next_retry_at'] = '2026-07-28T12:01:00Z';
$assert($decide($backoff)['reason_code'] === 'backoff_active', 'future backoff blocked');
$assert($decide($backoff, new DateTimeImmutable('2026-07-28T12:01:00Z'))['available'], 'elapsed backoff eligible');
$assert($decide($backoff, $now) === $decide($backoff, $now), 'same now deterministic');

$limit = $base();
$limit['delivery_completion']['attempt_count'] = 5;
$assert($decide($limit)['reason_code'] === 'attempt_limit', 'attempt limit blocked');
$limit['delivery_completion']['attempt_count'] = 4;
$assert($decide($limit)['available'], 'attempt budget available');
$invalidAttempts = $base();
unset($invalidAttempts['delivery_completion']['attempt_count']);
$assert($decide($invalidAttempts)['reason_code'] === 'insufficient_facts', 'missing attempts fail closed');
$invalidAttempts['delivery_completion']['attempt_count'] = -1;
$assert($decide($invalidAttempts)['reason_code'] === 'incompatible_state', 'invalid attempts blocked');
$invalidLease = $base();
$invalidLease['delivery_completion']['lease_expires_at'] = 'not-a-timestamp';
$assert($decide($invalidLease)['reason_code'] === 'insufficient_facts', 'invalid lease facts fail closed');
$missingBackoff = $base();
unset($missingBackoff['delivery_completion']['next_retry_at']);
$assert($decide($missingBackoff)['reason_code'] === 'insufficient_facts', 'missing backoff fact fails closed');
$invalidState = $base();
$invalidState['delivery_completion']['status'] = 'invented';
$assert(! $decide($invalidState)['available'], 'invalid status unavailable');

$pickup = $base();
$pickup['checkout']['fulfillment_method'] = 'pickup';
$pickup['business_completion']['fulfillment_method'] = 'pickup';
$pickup['delivery_completion']['status'] = 'not_required';
$pickup['fulfillment_completion'] = ['id' => 21, 'business_completion_id' => 11, 'status' => 'pending', 'attempt_count' => 0, 'next_retry_at' => null];
$assert($decide($pickup)['stage'] === 'fulfillment_completion', 'pickup skips physical delivery');
$pickup['deliveries'] = [['id' => 20, 'order_id' => 10, 'status' => 'pending']];
$assert(! $decide($pickup)['available'], 'pickup does not invent delivery branch');

$absentDeliveryCompletion = $base();
$absentDeliveryCompletion['delivery_completion'] = null;
$assert(
    $decide($absentDeliveryCompletion)['reason_code'] === 'insufficient_facts',
    'absent delivery materialization is not inferred'
);
$doneDelivery = $base();
$doneDelivery['delivery_completion']['status'] = 'completed';
$doneDelivery['deliveries'] = [['id' => 20, 'order_id' => 10, 'customer_id' => 7, 'minimarket_id' => 3, 'status' => 'pending']];
$doneDelivery['fulfillment_completion'] = ['id' => 21, 'business_completion_id' => 11, 'status' => 'pending', 'attempt_count' => 0, 'next_retry_at' => null];
$assert($decide($doneDelivery)['stage'] === 'fulfillment_completion', 'completed delivery stage is not reopened');

$uncertain = $base();
$uncertain['delivery_completion']['status'] = 'manual_review';
$assert($decide($uncertain)['reason_code'] === 'uncertain_operation', 'category B uncertainty blocked');

$historical792 = $pickup;
$historical792['fulfillment_completion']['status'] = 'completed';
$historical792['reservations'][0]['status'] = 'active';
$decision792 = $decide($historical792);
$assert(! $decision792['available'] && $decision792['reason_code'] === 'historical_remediation_required', '#792 facts stay historical');
$historical793 = $completed;
$historical793['reservations'][0]['status'] = 'active';
$decision793 = $decide($historical793);
$assert(! $decision793['available'] && $decision793['reason_code'] === 'historical_remediation_required', '#793 facts stay historical');
$resolution793 = $resolver->resolve(new OrderOperationalFacts($historical793, '2026-07-28T12:00:00Z'))->toArray();
$codes793 = array_column($resolution793['consistency']['findings'], 'code');
$assert(in_array('reservations_active_after_payment', $codes793, true), 'unrelated blocker preserved');
$assert(in_array('fulfillment_completed_without_branch', $codes793, true), 'branch blocker preserved');

$before = serialize($backoff);
$decide($backoff);
$assert(serialize($backoff) === $before, 'input not modified');
$assert(array_keys($eligible) === ['action_code', 'available', 'reason_code', 'requires_confirmation', 'risk', 'stage'], 'result shape closed');
$assert(array_diff([$eligible['reason_code']], OrderAdminActionPolicy::REASON_CODES) === [], 'reason allowlist');
$assert(! str_contains(json_encode($eligible, JSON_THROW_ON_ERROR), 'SQL'), 'no internal messages');
try {
    new VeciAhorra\Modules\Orders\Domain\Policies\OrderAdminActionDecision(
        OrderAdminActionPolicy::RETRY_DURABLE_PROCESSING,
        true,
        'active_lease',
        true,
        'medium',
        'delivery_completion'
    );
    $assert(false, 'contradictory decision accepted');
} catch (InvalidArgumentException) {
    $assert(true, 'contradictory decision rejected');
}

echo "order-admin-action-policy-test: OK ({$assertions} assertions)\n";
