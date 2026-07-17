<?php

declare(strict_types=1);

use VeciAhorra\Modules\CustomerPanel\Service\CustomerPurchaseStatusResolver;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

function assertPurchaseStatus(string $expected, array $context): void
{
    $actual = (new CustomerPurchaseStatusResolver())->resolve($context)->code;
    if ($actual !== $expected) {
        throw new RuntimeException("Esperado {$expected}; recibido {$actual}");
    }
}

$base = ['checkout_status' => 'payment_started', 'fulfillment_method' => 'delivery', 'deliveries' => []];
$logistics = array_replace($base, [
    'payment' => ['status' => 'paid'],
    'attempt' => ['reconciliation_status' => 'completed', 'financial_status' => 'approved', 'business_status' => 'completed'],
]);
assertPurchaseStatus('under_review', array_replace($base, ['inconsistent' => true]));
assertPurchaseStatus('pending_payment', ['checkout_status' => 'pending', 'deliveries' => []]);
assertPurchaseStatus('processing_payment', array_replace($base, ['attempt' => ['reconciliation_status' => 'processing']]));
assertPurchaseStatus('payment_rejected', array_replace($base, ['attempt' => ['reconciliation_status' => 'permanent_failure', 'financial_status' => 'rejected']]));
assertPurchaseStatus('under_review', array_replace($base, ['attempt' => ['reconciliation_status' => 'permanent_failure', 'financial_status' => null]]));
assertPurchaseStatus('payment_received', array_replace($base, ['payment' => ['status' => 'paid'], 'attempt' => ['reconciliation_status' => 'completed']]));
assertPurchaseStatus('preparing_order', array_replace($base, ['payment' => ['status' => 'paid'], 'attempt' => ['business_status' => 'completed', 'reconciliation_status' => 'completed', 'financial_status' => 'approved']]));
assertPurchaseStatus('preparing_delivery', array_replace($logistics, ['deliveries' => [['status' => 'pending']]]));
assertPurchaseStatus('out_for_delivery', array_replace($logistics, ['deliveries' => [['status' => 'picked_up'], ['status' => 'delivered']]]));
assertPurchaseStatus('delivered', array_replace($logistics, ['deliveries' => [['status' => 'delivered'], ['status' => 'delivered']]]));
assertPurchaseStatus('cancelled', ['checkout_status' => 'cancelled', 'deliveries' => []]);
assertPurchaseStatus('cancelled', array_replace($logistics, ['deliveries' => [['status' => 'cancelled'], ['status' => 'cancelled']]]));
assertPurchaseStatus('under_review', array_replace($logistics, ['deliveries' => [['status' => 'cancelled'], ['status' => 'pending']]]));
assertPurchaseStatus('preparing_order', ['checkout_status' => 'payment_started', 'fulfillment_method' => 'pickup', 'deliveries' => [], 'payment' => ['status' => 'paid'], 'attempt' => ['reconciliation_status' => 'completed', 'financial_status' => 'approved', 'business_status' => 'completed', 'delivery_completion_status' => 'not_required', 'fulfillment_completion_status' => 'completed']]);
assertPurchaseStatus('under_review', array_replace($base, ['attempt' => ['business_status' => 'mystery']]));
assertPurchaseStatus('under_review', array_replace($base, ['attempt' => ['financial_status' => 'mystery']]));
assertPurchaseStatus('preparing_order', array_replace($base, ['payment' => ['status' => 'paid'], 'attempt' => ['reconciliation_status' => 'completed', 'financial_status' => 'approved', 'business_status' => 'completed', 'delivery_completion_status' => 'completed', 'fulfillment_completion_status' => 'completed']]));
assertPurchaseStatus('under_review', ['checkout_status' => 'pending', 'deliveries' => [], 'attempt' => ['session_status' => 'create_ambiguous']]);
assertPurchaseStatus('under_review', ['checkout_status' => 'pending', 'deliveries' => [], 'attempt' => ['session_status' => 'ready']]);
assertPurchaseStatus('processing_payment', ['checkout_status' => 'pending', 'deliveries' => [], 'attempt' => ['session_status' => 'create_retryable']]);
assertPurchaseStatus('under_review', array_replace($logistics, ['deliveries' => [['status' => 'assigned'], ['status' => 'picked_up']]]));
assertPurchaseStatus('preparing_delivery', array_replace($logistics, ['deliveries' => [['status' => 'assigned'], ['status' => 'pending']]]));
assertPurchaseStatus('under_review', ['checkout_status' => 'mystery', 'deliveries' => []]);
assertPurchaseStatus('under_review', array_replace($base, ['attempt' => ['reconciliation_status' => 'mystery']]));
assertPurchaseStatus('under_review', array_replace($base, ['attempt' => ['session_status' => 'mystery']]));
assertPurchaseStatus('under_review', array_replace($base, ['attempt' => ['delivery_completion_status' => 'mystery']]));
assertPurchaseStatus('under_review', array_replace($base, ['attempt' => ['fulfillment_completion_status' => 'mystery']]));
assertPurchaseStatus('under_review', array_replace($logistics, ['deliveries' => [['status' => 'mystery']]]));

$source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/CustomerPanel/Service/CustomerPurchaseStatusResolver.php');
if (str_contains($source, 'Listo para retiro')) {
    throw new RuntimeException('El resolver implementa un estado no autorizado.');
}

echo "PASS customer-purchase-status-resolver-test\n";
