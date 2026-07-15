<?php

declare(strict_types=1);

use VeciAhorra\Modules\Payments\Service\PublicPaymentStatusService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function paymentStatusAssert(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

$service = new PublicPaymentStatusService();
$project = new ReflectionMethod($service, 'projectAttempt');
$select = new ReflectionMethod($service, 'select');
$base = [
    'id' => 1, 'public_id' => 'ps_' . str_repeat('A', 43),
    'session_status' => 'pending', 'session_expires_at' => '2099-01-01 00:00:00',
    'redirect_url' => null, 'environment' => 'integration',
];
$cases = [
    [['session_status' => 'pending'], 'pending', 'wait'],
    [['session_status' => 'create_processing'], 'pending', 'wait'],
    [['session_status' => 'create_ambiguous'], 'payment_verifying', 'wait'],
    [['session_status' => 'create_failed'], 'failed', 'contact_support'],
    [['session_status' => 'expired'], 'payment_expired', 'retry_payment'],
    [['return_processing_status' => 'processing'], 'payment_verifying', 'wait'],
    [['return_result_status' => 'approved'], 'payment_verifying', 'wait'],
    [['return_result_status' => 'rejected'], 'payment_rejected', 'retry_payment'],
    [['return_processing_status' => 'ambiguous'], 'manual_review', 'contact_support'],
    [['reconciliation_status' => 'pending'], 'payment_verifying', 'wait'],
    [['reconciliation_status' => 'processing'], 'payment_verifying', 'wait'],
    [['reconciliation_status' => 'retryable'], 'payment_verifying', 'wait'],
    [['reconciliation_status' => 'completed'], 'payment_approved_processing', 'wait'],
    [['reconciliation_status' => 'manual_review'], 'manual_review', 'contact_support'],
    [['reconciliation_status' => 'permanent_failure'], 'failed', 'contact_support'],
    [['business_status' => 'pending'], 'payment_approved_processing', 'wait'],
    [['delivery_status' => 'completed'], 'payment_approved_processing', 'wait'],
    [['fulfillment_status' => 'completed'], 'completed', 'view_order'],
    [['fulfillment_status' => 'manual_review'], 'manual_review', 'contact_support'],
    [['fulfillment_status' => 'permanent_failure'], 'failed', 'contact_support'],
];
foreach ($cases as [$overrides, $status, $action]) {
    $result = $project->invoke($service, array_merge($base, $overrides));
    paymentStatusAssert(
        $result['payment_status'] === $status && $result['next_action'] === $action,
        "Proyeccion incorrecta para {$status}."
    );
}

$completed = array_merge($base, ['id' => 1, 'fulfillment_status' => 'completed']);
$newPending = array_merge($base, ['id' => 2, 'session_status' => 'pending']);
paymentStatusAssert(
    $select->invoke($service, [$newPending, $completed])['id'] === 1,
    'Un intento nuevo oculto la compra completada.'
);
$oldRejected = array_merge($base, ['id' => 1, 'return_result_status' => 'rejected']);
$newReady = array_merge($base, ['id' => 2, 'session_status' => 'ready']);
paymentStatusAssert(
    $select->invoke($service, [$newReady, $oldRejected])['id'] === 2,
    'Un rechazo historico oculto el intento nuevo.'
);
$manual = array_merge($base, ['id' => 1, 'reconciliation_status' => 'manual_review']);
paymentStatusAssert(
    $select->invoke($service, [$newReady, $manual])['id'] === 1,
    'manual_review permitio ofrecer otro pago.'
);

echo "PASS public-payment-status-projection-test\n";
