<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Domain\Operational\InvariantCatalog;
use VeciAhorra\Modules\Orders\Domain\Operational\OperationalStateCatalog;
use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalFacts;
use VeciAhorra\Modules\Orders\Domain\Operational\OrderOperationalStateResolver;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$resolver = new OrderOperationalStateResolver();
$observedAt = '2026-07-26T16:00:00Z';
$resolve = static fn (array $facts): array => $resolver->resolve(new OrderOperationalFacts($facts, $observedAt))->toArray();

$base = static fn (): array => [
    'order' => ['id' => 10, 'customer_id' => 7, 'minimarket_id' => 3, 'status' => 'reserved', 'total' => '1000.00', 'currency' => 'CLP', 'created_at' => '2026-07-26T10:01:00Z', 'updated_at' => '2026-07-26T10:01:00Z'],
    'order_items' => [['id' => 100, 'product_id' => 20, 'inventory_id' => 30, 'quantity' => 2, 'unit_price' => '500.00', 'subtotal' => '1000.00', 'updated_at' => '2026-07-26T10:01:00Z']],
    'checkout' => ['id' => 40, 'customer_id' => 7, 'status' => 'pending', 'fulfillment_method' => 'pickup', 'total_amount' => '1000.00', 'currency' => 'CLP', 'created_at' => '2026-07-26T10:00:00Z', 'updated_at' => '2026-07-26T10:00:00Z'],
    'checkout_order_links' => [['order_id' => 10]],
    'reservations' => [['id' => 50, 'order_id' => 10, 'product_id' => 20, 'inventory_id' => 30, 'minimarket_id' => 3, 'quantity' => 2, 'status' => 'active', 'reserved_at' => '2026-07-26T10:02:00Z', 'updated_at' => '2026-07-26T10:02:00Z']],
    'payment_attempts' => [],
    'payment_order_links' => [],
    'business_order_links' => [],
    'deliveries' => [],
    'delivery_tracking' => [],
    'read_failures' => [],
    'historical_profile' => 'none',
];
$paidPickup = static function () use ($base): array {
    $facts = $base();
    $facts['order']['status'] = 'paid';
    $facts['checkout']['status'] = 'payment_started';
    $facts['reservations'][0]['status'] = 'consumed';
    $facts['payment_session'] = ['id' => 60, 'checkout_id' => 40, 'status' => 'confirmed', 'created_at' => '2026-07-26T10:03:00Z', 'confirmed_at' => '2026-07-26T10:06:00Z', 'updated_at' => '2026-07-26T10:06:00Z'];
    $facts['financial_evidence'] = ['id' => 70, 'status' => 'approved', 'validated' => true, 'obtained_at' => '2026-07-26T10:04:00Z', 'validated_at' => '2026-07-26T10:05:00Z', 'updated_at' => '2026-07-26T10:05:00Z'];
    $facts['reconciliation'] = ['id' => 80, 'checkout_id' => 40, 'status' => 'completed', 'lease_version' => 1, 'completed_at' => '2026-07-26T10:07:00Z', 'updated_at' => '2026-07-26T10:07:00Z'];
    $facts['payment'] = ['id' => 90, 'checkout_id' => 40, 'status' => 'paid', 'amount' => '1000.00', 'currency' => 'CLP', 'paid_at' => '2026-07-26T10:08:00Z', 'updated_at' => '2026-07-26T10:08:00Z'];
    $facts['payment_order_links'] = [['order_id' => 10]];
    $facts['business_completion'] = ['id' => 110, 'status' => 'completed', 'lease_version' => 1, 'completed_at' => '2026-07-26T10:09:00Z', 'updated_at' => '2026-07-26T10:09:00Z'];
    $facts['business_order_links'] = [['order_id' => 10]];
    $facts['delivery_completion'] = ['id' => 120, 'status' => 'not_required', 'lease_version' => 1, 'completed_at' => '2026-07-26T10:10:00Z', 'updated_at' => '2026-07-26T10:10:00Z'];
    $facts['fulfillment_completion'] = ['id' => 130, 'status' => 'completed', 'lease_version' => 1, 'completed_at' => '2026-07-26T10:11:00Z', 'updated_at' => '2026-07-26T10:11:00Z'];
    return $facts;
};
$paidDelivery = static function () use ($paidPickup): array {
    $facts = $paidPickup();
    $facts['order']['status'] = 'delivered';
    $facts['checkout']['fulfillment_method'] = 'delivery';
    $facts['delivery_completion']['status'] = 'completed';
    $facts['deliveries'] = [['id' => 140, 'order_id' => 10, 'customer_id' => 7, 'minimarket_id' => 3, 'status' => 'delivered', 'courier_id' => 8, 'created_at' => '2026-07-26T10:10:00Z', 'updated_at' => '2026-07-26T10:12:00Z']];
    $facts['delivery_tracking'] = [['id' => 150, 'event' => 'delivered', 'created_at' => '2026-07-26T10:12:00Z', 'latitude' => '-33.1', 'token' => 'must-not-leak']];
    return $facts;
};

// Closed catalogs and invalid values.
foreach (['COMMERCIAL', 'FINANCIAL', 'RESERVATION', 'PROCESSING', 'FULFILLMENT', 'DELIVERY', 'PAYMENT_SESSION', 'PRIMARY', 'CONSISTENCY', 'SEVERITY'] as $name) {
    $values = constant(OperationalStateCatalog::class . '::' . $name);
    $assert($values === array_values(array_unique($values)), $name . ' must contain unique values');
}
try {
    OperationalStateCatalog::assert('primary', 'invented');
    $assert(false, 'arbitrary catalog value should fail');
} catch (InvalidArgumentException) {
    $assert(true, 'arbitrary catalog value rejected');
}
$assert(count(InvariantCatalog::codes()) === 31, 'invariant catalog must contain exactly 31 codes');
$assert(count(array_unique(InvariantCatalog::codes())) === 31, 'invariant codes must be unique');

// Baseline and primary-state precedence scenarios.
$reserved = $resolve($base());
$assert($reserved['primary_state'] === 'reserved', 'active reservation must resolve reserved');
$assert($reserved['consistency']['classification'] === 'consistent', 'baseline must be consistent');
$scenarios = [];
$pending = $base();
$pending['payment_session'] = ['id' => 60, 'checkout_id' => 40, 'status' => 'ready', 'updated_at' => '2026-07-26T10:03:00Z'];
$pending['checkout']['status'] = 'payment_started';
$scenarios['payment_in_progress'] = $pending;
$rejected = $pending;
$rejected['financial_evidence'] = ['id' => 70, 'status' => 'rejected', 'validated' => true, 'validated_at' => '2026-07-26T10:04:00Z'];
$scenarios['payment_rejected'] = $rejected;
$expired = $base();
$expired['checkout']['status'] = 'expired';
$expired['reservations'][0]['status'] = 'expired';
$expired['reservations'][0]['released_at'] = '2026-07-26T10:20:00Z';
$scenarios['expired'] = $expired;
$processing = $paidPickup();
unset($processing['business_completion'], $processing['delivery_completion'], $processing['fulfillment_completion']);
$processing['payment'] = null;
$processing['payment_order_links'] = [];
$processing['business_order_links'] = [];
$processing['reconciliation']['status'] = 'processing';
$processing['order']['status'] = 'reserved';
$processing['reservations'][0]['status'] = 'active';
$scenarios['post_payment_processing'] = $processing;
$retry = $processing;
$retry['reconciliation']['status'] = 'retryable';
$scenarios['post_payment_processing_retry'] = $retry;
$failed = $paidPickup();
$failed['fulfillment_completion']['status'] = 'permanent_failure';
$scenarios['failed'] = $failed;
$scenarios['completed'] = $paidPickup();
$scenarios['delivery_completed'] = $paidDelivery();
foreach ($scenarios as $expected => $facts) {
    $actual = $resolve($facts)['primary_state'];
    $expectedState = match ($expected) {
        'post_payment_processing_retry' => 'post_payment_processing',
        'delivery_completed' => 'completed',
        default => $expected,
    };
    $assert($actual === $expectedState, $expected . ' resolved as ' . $actual);
}

// Every invariant has a negative baseline and a positive triggering scenario.
$tap = static function (array $value, callable $mutator): array {
    $mutator($value);
    return $value;
};
$mutators = [
    'order_status_unknown' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['order']['status'] = 'bogus'; }),
    'paid_without_financial_evidence' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['order']['status'] = 'paid'; }),
    'approved_without_business_processing' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['financial_evidence'] = ['status' => 'approved', 'validated' => true]; $x['reconciliation'] = ['status' => 'completed']; }),
    'business_completed_without_paid_order' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['business_completion'] = ['status' => 'completed']; }),
    'delivered_without_delivery_evidence' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['order']['status'] = 'delivered'; $x['checkout']['fulfillment_method'] = 'delivery'; }),
    'delivery_completed_order_not_delivered' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['checkout']['fulfillment_method'] = 'delivery'; $x['deliveries'] = [['id' => 1, 'order_id' => 10, 'customer_id' => 7, 'minimarket_id' => 3, 'status' => 'delivered']]; }),
    'pickup_has_delivery' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['deliveries'] = [['id' => 1, 'order_id' => 10, 'status' => 'pending']]; }),
    'pickup_completion_invalid' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['delivery_completion'] = ['status' => 'completed']; }),
    'delivery_integrity_mismatch' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['checkout']['fulfillment_method'] = 'delivery'; $x['deliveries'] = [['id' => 1, 'order_id' => 999, 'status' => 'pending']]; }),
    'fulfillment_completed_without_branch' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['fulfillment_completion'] = ['status' => 'completed']; }),
    'reservation_items_mismatch' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['reservations'][0]['quantity'] = 1; }),
    'active_reservation_after_terminal_release' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['reservations'][0]['terminal_release_evidence'] = true; }),
    'reservations_active_after_payment' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['payment'] = ['status' => 'paid']; }),
    'reservations_consumed_without_approval' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['reservations'][0]['status'] = 'consumed'; }),
    'reservation_terminal_mixed' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['order_items'][] = ['id' => 101, 'product_id' => 21, 'inventory_id' => 31, 'quantity' => 1, 'unit_price' => '0', 'subtotal' => '0']; $x['reservations'][] = ['id' => 51, 'product_id' => 21, 'inventory_id' => 31, 'quantity' => 1, 'status' => 'released']; $x['reservations'][0]['status'] = 'expired'; }),
    'stock_double_terminal_evidence' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['reservations'][0]['consumed_evidence'] = true; $x['reservations'][0]['restored_evidence'] = true; }),
    'order_item_subtotal_mismatch' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['order_items'][0]['subtotal'] = '999.00'; }),
    'order_total_mismatch' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['order']['total'] = '999.00'; $x['checkout']['total_amount'] = '999.00'; }),
    'checkout_total_mismatch' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['checkout']['total_amount'] = '999.00'; }),
    'order_store_mismatch' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['reservations'][0]['minimarket_id'] = 999; }),
    'checkout_order_relation_missing' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['checkout_order_links'] = []; }),
    'checkout_order_owner_mismatch' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['checkout']['customer_id'] = 999; }),
    'operational_order_set_mismatch' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['payment_order_links'] = [['order_id' => 999]]; }),
    'payment_flow_mismatch' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['payment_session'] = ['status' => 'pending', 'checkout_id' => 999]; }),
    'payment_amount_mismatch' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['payment'] = ['status' => 'paid', 'amount' => '999.00', 'currency' => 'CLP']; }),
    'financial_terminal_regression' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['diagnostics']['financial_terminal_regression'] = true; }),
    'processing_lease_expired' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['reconciliation'] = ['status' => 'processing', 'lease_expires_at' => '2026-07-26T15:00:00Z']; }),
    'processing_retry_scheduled' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['reconciliation'] = ['status' => 'retryable']; }),
    'current_catalog_reference_missing' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['order_items'][0]['current_product_exists'] = false; }),
    'current_store_missing' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['order']['current_store_exists'] = false; }),
    'read_failure' => static fn (array $f): array => $tap($f, static function (array &$x): void { $x['read_failures'] = [['scope' => 'optional', 'code' => 'delivery_unavailable']]; }),
];
$invariantMetadata = [
    'order_status_unknown' => ['error', 'commercial', true],
    'paid_without_financial_evidence' => ['critical', 'financial', true],
    'approved_without_business_processing' => ['warning', 'processing', true],
    'business_completed_without_paid_order' => ['critical', 'processing', true],
    'delivered_without_delivery_evidence' => ['critical', 'delivery', true],
    'delivery_completed_order_not_delivered' => ['error', 'delivery', true],
    'pickup_has_delivery' => ['error', 'delivery', true],
    'pickup_completion_invalid' => ['error', 'fulfillment', true],
    'delivery_integrity_mismatch' => ['critical', 'delivery', true],
    'fulfillment_completed_without_branch' => ['critical', 'fulfillment', true],
    'reservation_items_mismatch' => ['critical', 'reservations', true],
    'active_reservation_after_terminal_release' => ['critical', 'reservations', true],
    'reservations_active_after_payment' => ['critical', 'reservations', true],
    'reservations_consumed_without_approval' => ['critical', 'reservations', true],
    'reservation_terminal_mixed' => ['error', 'reservations', true],
    'stock_double_terminal_evidence' => ['critical', 'reservations', true],
    'order_item_subtotal_mismatch' => ['critical', 'commercial', true],
    'order_total_mismatch' => ['critical', 'commercial', true],
    'checkout_total_mismatch' => ['critical', 'commercial', true],
    'order_store_mismatch' => ['critical', 'commercial', true],
    'checkout_order_relation_missing' => ['error', 'commercial', true],
    'checkout_order_owner_mismatch' => ['critical', 'commercial', true],
    'operational_order_set_mismatch' => ['critical', 'commercial', true],
    'payment_flow_mismatch' => ['critical', 'financial', true],
    'payment_amount_mismatch' => ['critical', 'financial', true],
    'financial_terminal_regression' => ['critical', 'financial', true],
    'processing_lease_expired' => ['warning', 'processing', true],
    'processing_retry_scheduled' => ['info', 'processing', false],
    'current_catalog_reference_missing' => ['warning', 'commercial', false],
    'current_store_missing' => ['warning', 'commercial', true],
    'read_failure' => ['error', 'read', true],
];
$assert(array_keys($mutators) === InvariantCatalog::codes(), 'test mutators must cover the exact invariant catalog');
$assert(array_keys($invariantMetadata) === InvariantCatalog::codes(), 'metadata matrix must cover the exact invariant catalog');
$baselineCodes = array_column($reserved['consistency']['findings'], 'code');
foreach ($mutators as $code => $mutate) {
    $assert(! in_array($code, $baselineCodes, true), $code . ' negative scenario');
    $positiveFindings = $resolve($mutate($base()))['consistency']['findings'];
    $codes = array_column($positiveFindings, 'code');
    $assert(in_array($code, $codes, true), $code . ' positive scenario');
    $finding = array_values(array_filter($positiveFindings, static fn (array $item): bool => $item['code'] === $code))[0];
    [$severity, $dimension, $blocker] = $invariantMetadata[$code];
    $assert($finding['severity'] === $severity, $code . ' exact severity');
    $assert($finding['affected_dimension'] === $dimension, $code . ' exact affected dimension');
    $assert($finding['blocker'] === $blocker, $code . ' exact blocker');
    $assert($finding['title'] !== '' && $finding['description'] !== '', $code . ' safe human descriptors');
    $assert(array_keys($finding['evidence']) === ['order_id', 'code', 'reservation_count', 'delivery_count'], $code . ' minimal evidence shape');
}

// Historical tolerance, multiple findings, stable ordering and no duplicates.
$historical = $base();
$historical['order']['status'] = 'paid';
$historical['historical_profile'] = 'legacy-paid-v1';
$historicalResult = $resolve($historical);
$historicalFinding = array_values(array_filter($historicalResult['consistency']['findings'], static fn (array $finding): bool => $finding['code'] === 'paid_without_financial_evidence'))[0];
$assert($historicalFinding['severity'] === 'warning' && $historicalFinding['historical_tolerance'] === true && $historicalFinding['blocker'] === false, 'historical paid tolerance does not block');
$historical['reservations'][0]['status'] = 'consumed';
$assert($resolve($historical)['primary_state'] === 'confirmed', 'coherent historical paid snapshot resolves confirmed');
$multi = $base();
$multi['order']['status'] = 'bogus';
$multi['order_items'][0]['subtotal'] = '1.00';
$multiResult = $resolve($multi);
$multiCodes = array_column($multiResult['consistency']['findings'], 'code');
$assert(count($multiCodes) >= 3 && count($multiCodes) === count(array_unique($multiCodes)), 'multiple findings without duplicates');
$catalogOrder = array_flip(InvariantCatalog::codes());
$positions = array_map(static fn (string $code): int => $catalogOrder[$code], $multiCodes);
$sortedPositions = $positions;
sort($sortedPositions);
$assert($positions === $sortedPositions, 'findings use deterministic catalog order');

// Five consistency classes and explicit precedence conflicts.
$warningFacts = $base();
$warningFacts['order_items'][0]['current_product_exists'] = false;
$warningResolution = $resolve($warningFacts);
$assert($warningResolution['consistency']['classification'] === 'warning', 'tolerable finding resolves warning');
$assert($warningResolution['consistency']['findings'][0]['historical_tolerance'] === true, 'missing current catalog reference is explicitly historical');
$degradedFacts = $base();
$degradedFacts['read_failures'] = [['scope' => 'optional', 'code' => 'optional_read_failed']];
$assert($resolve($degradedFacts)['consistency']['classification'] === 'degraded', 'partial read failure resolves degraded');
$unknownFacts = $base();
$unknownFacts['read_failures'] = [['scope' => 'core', 'code' => 'order_unavailable']];
$assert($resolve($unknownFacts)['consistency']['classification'] === 'unknown', 'core read failure resolves unknown');
$inconsistentFacts = $base();
$inconsistentFacts['order']['status'] = 'bogus';
$assert($resolve($inconsistentFacts)['consistency']['classification'] === 'inconsistent', 'blocking contradiction resolves inconsistent');

$manualReview = $base();
$manualReview['reconciliation'] = ['id' => 80, 'checkout_id' => 40, 'status' => 'manual_review'];
$assert($resolve($manualReview)['primary_state'] === 'manual_review', 'manual review precedes reservation');
$processingFailure = $processing;
$processingFailure['reconciliation']['status'] = 'permanent_failure';
$assert($resolve($processingFailure)['primary_state'] === 'failed', 'processing failure precedes approved payment');
$assert($resolve($retry)['dimensions']['processing'] === 'retry_wait', 'retryable processing resolves retry_wait dimension');
$cancelledApproved = $processing;
$cancelledApproved['checkout']['status'] = 'cancelled';
$assert($resolve($cancelledApproved)['primary_state'] === 'post_payment_processing', 'approved processing precedes cancelled Checkout');
$rejectedReusable = $rejected;
$rejectedReusable['checkout']['status'] = 'payment_started';
$assert($resolve($rejectedReusable)['primary_state'] === 'payment_rejected', 'rejection does not invent cancellation');
$activeExpired = $base();
$activeExpired['checkout']['status'] = 'expired';
$assert($resolve($activeExpired)['primary_state'] === 'expired', 'Checkout expiration precedes active reservation');
$warningCompleted = $paidPickup();
$warningCompleted['order_items'][0]['current_product_exists'] = false;
$assert($resolve($warningCompleted)['primary_state'] === 'completed', 'historical warning does not hide valid completion');
$blockedCompleted = $paidPickup();
$blockedCompleted['order']['status'] = 'bogus';
$assert($resolve($blockedCompleted)['primary_state'] === 'inconsistent', 'blocking finding precedes apparent completion');
$deliveredContradictory = $paidDelivery();
$deliveredContradictory['deliveries'][0]['status'] = 'pending';
$assert($resolve($deliveredContradictory)['primary_state'] === 'inconsistent', 'delivered Order with contradictory Delivery is inconsistent');
$unknownSource = $base();
$unknownSource['payment_session'] = ['id' => 60, 'status' => 'future_status'];
$assert($resolve($unknownSource)['primary_state'] === 'unknown', 'unknown relevant source remains unknown');

// Determinism, input immutability, timeline stable tie-break and source omission.
$facts = $paidDelivery();
$original = $facts;
$first = $resolve($facts);
$facts['order_items'] = array_reverse($facts['order_items']);
$facts['reservations'] = array_reverse($facts['reservations']);
$facts['deliveries'] = array_reverse($facts['deliveries']);
$second = $resolve($facts);
$assert($first === $second, 'same facts in different collection order must produce same output');
$assert($original === $paidDelivery(), 'fixture and resolver inputs remain unchanged');
$timestamps = array_column($first['timeline'], 'occurred_at');
$sortedTimestamps = $timestamps;
sort($sortedTimestamps, SORT_STRING);
$assert($timestamps === $sortedTimestamps, 'timeline chronological order');
$assert(count($first['timeline']) >= 10, 'timeline derives persisted events');
$sameMoment = array_values(array_filter($first['timeline'], static fn (array $event): bool => $event['occurred_at'] === '2026-07-26T10:10:00Z'));
$sameMomentRanks = array_column($sameMoment, 'source_rank');
$sortedSameMomentRanks = $sameMomentRanks;
sort($sortedSameMomentRanks, SORT_NUMERIC);
$assert($sameMomentRanks === $sortedSameMomentRanks, 'same timestamp uses stable source-rank tie break');
$timelineTypes = array_column($first['timeline'], 'type');
foreach (['checkout_created', 'order_created', 'stock_reserved', 'payment_started', 'financial_evidence_obtained', 'financial_evidence_validated', 'payment_confirmed', 'reconciliation_completed', 'business_completed', 'delivery_processing_completed', 'delivery_created', 'delivery_event', 'fulfillment_completed'] as $timelineType) {
    $assert(in_array($timelineType, $timelineTypes, true), 'timeline includes ' . $timelineType);
}
$expiredTimelineTypes = array_column($resolve($expired)['timeline'], 'type');
$assert(in_array('reservation_expired', $expiredTimelineTypes, true) && in_array('checkout_expired', $expiredTimelineTypes, true), 'timeline includes persisted expiration evidence');
$serialized = json_encode($first, JSON_THROW_ON_ERROR);
foreach (['latitude', 'token', '-33.1', 'must-not-leak', 'payload', 'stack trace', 'SELECT '] as $forbidden) {
    $assert(! str_contains($serialized, $forbidden), 'output must exclude sensitive/internal material: ' . $forbidden);
}
$withoutTimestamp = $base();
unset($withoutTimestamp['reservations'][0]['reserved_at']);
$assert(! in_array('stock_reserved', array_column($resolve($withoutTimestamp)['timeline'], 'type'), true), 'event without timestamp must be omitted');
$invalidTimestamp = $base();
$invalidTimestamp['reservations'][0]['reserved_at'] = 'not-a-timestamp';
$assert(! in_array('stock_reserved', array_column($resolve($invalidTimestamp)['timeline'], 'type'), true), 'event with invalid timestamp must be omitted');

// Operational version sensitivity and irrelevance.
$version = $first['concurrency']['operational_version'];
$assert((bool) preg_match('/^orders-operational-v1:[A-Za-z0-9_-]{43}$/', $version), 'operational version contains a base64url SHA-256 digest');
$reordered = $paidDelivery();
$reordered['delivery_tracking'] = array_reverse($reordered['delivery_tracking']);
$assert($resolve($reordered)['concurrency']['operational_version'] === $version, 'fingerprint ignores input order');
foreach ([
    static function (array &$f): void { $f['payment']['status'] = 'pending'; },
    static function (array &$f): void { $f['reservations'][0]['status'] = 'released'; },
    static function (array &$f): void { $f['payment_session']['status'] = 'ready'; },
    static function (array &$f): void { $f['reconciliation']['lease_version'] = 2; },
    static function (array &$f): void { $f['business_completion']['lease_version'] = 2; },
    static function (array &$f): void { $f['deliveries'][0]['status'] = 'picked_up'; },
    static function (array &$f): void { $f['fulfillment_completion']['status'] = 'pending'; },
    static function (array &$f): void { $f['order']['updated_at'] = '2026-07-26T10:02:00Z'; },
    static function (array &$f): void { $f['checkout']['status'] = 'expired'; },
] as $change) {
    $changed = $paidDelivery();
    $change($changed);
    $assert($resolve($changed)['concurrency']['operational_version'] !== $version, 'relevant authority must change operational version');
}
$irrelevant = $paidDelivery();
$irrelevant['order']['customer_email'] = 'private@example.test';
$irrelevant['payment_session']['provider_payload'] = ['token' => 'private'];
$irrelevant['delivery_tracking'][0]['latitude'] = '-10';
$assert($resolve($irrelevant)['concurrency']['operational_version'] === $version, 'irrelevant and PII data must not change fingerprint');
$equivalent = $paidDelivery();
$equivalent['order']['id'] = '10';
$equivalent['order']['total'] = '1000.0';
$equivalent['order']['updated_at'] = '2026-07-26T06:01:00-04:00';
$assert($resolve($equivalent)['concurrency']['operational_version'] === $version, 'equivalent integer, money and timestamp representations canonicalize equally');

// Arrays returned by toArray cannot mutate the readonly resolution.
$resolutionObject = $resolver->resolve(new OrderOperationalFacts($paidPickup(), $observedAt));
$exported = $resolutionObject->toArray();
$exported['dimensions']['financial'] = 'invented';
$exported['consistency']['findings'][] = ['code' => 'invented'];
$freshExport = $resolutionObject->toArray();
$assert($freshExport['dimensions']['financial'] === 'approved', 'export mutation cannot change readonly dimensions');
$assert(! in_array('invented', array_column($freshExport['consistency']['findings'], 'code'), true), 'export mutation cannot change readonly findings');

// Programmer errors are explicit and safe.
try {
    new OrderOperationalFacts([], $observedAt);
    $assert(false, 'missing Order should be rejected');
} catch (InvalidArgumentException $exception) {
    $assert(! str_contains($exception->getMessage(), 'SELECT'), 'programmer error is safe');
}

echo 'PASS order-operational-state-resolver-test assertions=' . $assertions . PHP_EOL;
