<?php

declare(strict_types=1);

use VeciAhorra\Modules\Orders\Services\OrderAdminActionPolicyIntegration;
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
$integration = new OrderAdminActionPolicyIntegration();
$now = new DateTimeImmutable('2026-07-26T16:00:00Z');
$evaluate = static fn (array $base, array $bundle, ?DateTimeImmutable $at = null): array =>
    $integration->evaluate($base, $bundle, '2026-07-26T16:00:00Z', $at ?? $now)->toArray();
$fixture = static function (): array {
    $base = OrderAdminReadFixture::base(10, 'delivery');
    $base['order_status'] = 'paid';
    $bundle = OrderAdminReadFixture::bundle(10, 'delivery');
    $bundle['deliveries'][0]['status'] = 'pending';
    foreach (['reconciliations', 'business_completions', 'delivery_completions', 'fulfillment_completions'] as $key) {
        $bundle[$key][0]['attempt_count'] = 1;
        $bundle[$key][0]['next_retry_at'] = null;
    }
    return [$base, $bundle];
};

// Each canonical durable stage is mapped from the already-loaded bundle.
[$base, $bundle] = $fixture();
$bundle['reconciliations'][0]['status'] = 'pending';
$bundle['business_completions'] = $bundle['delivery_completions'] = $bundle['fulfillment_completions'] = [];
$assert($evaluate($base, $bundle)['stage'] === 'reconciliation', 'reconciliation mapped');

[$base, $bundle] = $fixture();
$bundle['business_completions'][0]['status'] = 'retryable';
$bundle['delivery_completions'] = $bundle['fulfillment_completions'] = [];
$assert($evaluate($base, $bundle)['stage'] === 'business_completion', 'business completion mapped');

[$base, $bundle] = $fixture();
$bundle['delivery_completions'][0]['status'] = 'pending';
$bundle['fulfillment_completions'] = [];
$delivery = $evaluate($base, $bundle);
$assert(
    $delivery['available'] && $delivery['stage'] === 'delivery_completion',
    'delivery completion available: ' . json_encode($delivery)
);

[$base, $bundle] = $fixture();
$bundle['fulfillment_completions'][0]['status'] = 'retryable';
$fulfillment = $evaluate($base, $bundle);
$assert($fulfillment['available'] && $fulfillment['stage'] === 'fulfillment_completion', 'fulfillment completion available');

// Missing or malformed facts close safely.
[$base, $bundle] = $fixture();
$bundle['delivery_completions'] = $bundle['fulfillment_completions'] = [];
$assert($evaluate($base, $bundle)['reason_code'] === 'insufficient_facts', 'absent stage is not inferred');
[$base, $bundle] = $fixture();
$bundle['delivery_completions'][0]['status'] = 'pending';
unset($bundle['delivery_completions'][0]['next_retry_at']);
$bundle['fulfillment_completions'] = [];
$missingBackoff = $evaluate($base, $bundle);
$assert(
    $missingBackoff['reason_code'] === 'insufficient_facts',
    'missing next_retry_at closes safely: ' . json_encode($missingBackoff)
);
$bundle['delivery_completions'][0]['next_retry_at'] = 'invalid';
$assert($evaluate($base, $bundle)['reason_code'] === 'insufficient_facts', 'invalid next_retry_at closes safely');
$invalid = $integration->evaluate([], [], 'invalid', $now)->toArray();
$assert($invalid['reason_code'] === 'insufficient_facts', 'invalid assembled input closes safely');

// Canonical policy closure families remain intact through composition.
[$base, $bundle] = $fixture();
$base['order_status'] = 'delivered';
$bundle['deliveries'][0]['status'] = 'delivered';
$assert($evaluate($base, $bundle)['reason_code'] === 'terminal_stage', 'terminal pipeline blocked');
[$base, $bundle] = $fixture();
$bundle['delivery_completions'][0]['status'] = 'processing';
$bundle['delivery_completions'][0]['lease_expires_at'] = '2026-07-26T16:01:00Z';
$bundle['fulfillment_completions'] = [];
$assert($evaluate($base, $bundle)['reason_code'] === 'active_lease', 'active lease blocked');
$bundle['delivery_completions'][0]['lease_expires_at'] = '2026-07-26T15:59:00Z';
$assert($evaluate($base, $bundle)['available'], 'expired lease permits evaluation');
[$base, $bundle] = $fixture();
$bundle['delivery_completions'][0]['status'] = 'pending';
$bundle['delivery_completions'][0]['next_retry_at'] = '2026-07-26T16:01:00Z';
$bundle['fulfillment_completions'] = [];
$assert($evaluate($base, $bundle)['reason_code'] === 'backoff_active', 'backoff blocked');
[$base, $bundle] = $fixture();
$bundle['delivery_completions'][0]['status'] = 'retryable';
$bundle['delivery_completions'][0]['attempt_count'] = 5;
$bundle['fulfillment_completions'] = [];
$assert($evaluate($base, $bundle)['reason_code'] === 'attempt_limit', 'attempt limit blocked');
[$base, $bundle] = $fixture();
$bundle['delivery_completions'][0]['status'] = 'manual_review';
$bundle['fulfillment_completions'] = [];
$assert($evaluate($base, $bundle)['reason_code'] === 'uncertain_operation', 'uncertain operation blocked');
[$base, $bundle] = $fixture();
$bundle['reservations'][0]['status'] = 'active';
$assert($evaluate($base, $bundle)['reason_code'] === 'historical_remediation_required', 'historical remediation separated');
[$base, $bundle] = $fixture();
$base['total'] = '999.00';
$bundle['delivery_completions'][0]['status'] = 'pending';
$bundle['fulfillment_completions'] = [];
$assert($evaluate($base, $bundle)['reason_code'] === 'blocking_inconsistency', 'blocking inconsistency preserved');

// No warnings, hidden clock, mutations or public read-model exposure.
[$base, $bundle] = $fixture();
$bundle['delivery_completions'][0]['status'] = 'pending';
unset($bundle['delivery_completions'][0]['next_retry_at']);
$bundle['fulfillment_completions'] = [];
$before = serialize([$base, $bundle]);
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = [$severity, $message];
    return true;
});
try {
    $evaluate($base, $bundle);
} finally {
    restore_error_handler();
}
$assert($warnings === [], 'missing next_retry_at produces no warning');
$assert(serialize([$base, $bundle]) === $before, 'integration does not mutate inputs');

echo "order-admin-action-policy-integration-test: OK ({$assertions} assertions)\n";
