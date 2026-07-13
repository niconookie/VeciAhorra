<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Orders\Repositories\OrderRepository;
use VeciAhorra\Modules\Payments\Models\PaymentConfirmationAudit;
use VeciAhorra\Modules\Payments\Repository\PaymentConfirmationAuditRepository;
use VeciAhorra\Modules\Payments\Repository\PaymentRepository;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;
use VeciAhorra\Modules\Payments\Support\PaymentConfirmationFingerprint;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertConfirmationPersistence(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

global $wpdb;

$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$sessions = new PaymentSessionRepository();
$payments = new PaymentRepository();
$audits = new PaymentConfirmationAuditRepository();
$orders = new OrderRepository();
$nonce = bin2hex(random_bytes(8));
$sessionPublicId = 'ps_' . substr(hash('sha256', $nonce), 0, 43);
$otherSessionPublicId = 'ps_' . substr(hash('sha256', $nonce . '2'), 0, 43);
$correlationId = 'corr_' . substr(hash('sha256', $nonce), 0, 24);

try {
    $sessions->findForUpdate(1);
    throw new RuntimeException('PaymentSession permitio lock fuera de transaccion.');
} catch (PersistenceException) {
}

assertConfirmationPersistence(
    $wpdb->query('START TRANSACTION') !== false,
    'No se inicio la transaccion de infraestructura.'
);

try {
    $now = current_time('mysql');
    $wpdb->insert($prefix . 'checkouts', [
        'public_id' => 'chk_' . substr(hash('sha256', $nonce), 0, 43),
        'owner_type' => 'user',
        'user_id' => 990001,
        'session_id' => null,
        'status' => 'payment_started',
        'currency' => 'CLP',
        'total_amount' => '3000.00',
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $checkoutId = (int) $wpdb->insert_id;
    $orderIds = [];

    foreach ([1000, 2000] as $index => $amount) {
        $wpdb->insert($prefix . 'orders', [
            'customer_id' => 990001,
            'minimarket_id' => 990100 + $index,
            'total' => number_format($amount, 2, '.', ''),
            'status' => 'reserved',
            'reservation_expires_at' => gmdate(
                'Y-m-d H:i:s',
                time() + 3600
            ),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderIds[] = (int) $wpdb->insert_id;
    }

    foreach ($orderIds as $orderId) {
        $wpdb->insert($prefix . 'checkout_orders', [
            'checkout_id' => $checkoutId,
            'order_id' => $orderId,
            'created_at' => $now,
        ]);
    }

    $paymentId = $payments->create([
        'payment_reference' => 'PAY-B1-' . strtoupper($nonce),
        'customer_id' => 990001,
        'amount' => '3000.00',
        'currency' => 'CLP',
        'status' => 'pending',
        'provider' => 'webpay_plus',
        'provider_reference' => null,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        'paid_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $payments->attachOrders($paymentId, $orderIds, $now);
    $sessionId = $sessions->create([
        'public_id' => $sessionPublicId,
        'checkout_id' => $checkoutId,
        'payment_id' => null,
        'idempotency_key' => 'b1-key-' . $nonce,
        'request_fingerprint' => hash('sha256', 'request-' . $nonce),
        'status' => 'ready',
        'provider' => 'webpay_plus',
        'provider_session_id' => str_repeat('T', 64),
        'redirect_url' => null,
        'currency' => 'CLP',
        'amount' => '3000.00',
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $sessions->linkPayment($sessionId, $paymentId);
    assertConfirmationPersistence(
        (int) ($sessions->findByPaymentId($paymentId)['id'] ?? 0)
            === $sessionId,
        'No se recupero PaymentSession por Payment.'
    );
    assertConfirmationPersistence(
        (int) ($payments->findByPaymentSessionId($sessionId)['id'] ?? 0)
            === $paymentId,
        'No se recupero Payment por PaymentSession.'
    );
    assertConfirmationPersistence(
        (int) ($sessions->findForUpdate($sessionId)['id'] ?? 0) === $sessionId,
        'No se bloqueo PaymentSession.'
    );
    assertConfirmationPersistence(
        (int) ($payments->findByPaymentSessionIdForUpdate($sessionId)['id']
            ?? 0) === $paymentId,
        'No se bloqueo Payment.'
    );
    $lockedOrders = $orders->findManyForUpdate(array_reverse($orderIds));
    assertConfirmationPersistence(
        array_map('intval', array_column($lockedOrders, 'id')) === $orderIds,
        'Las Orders no se bloquearon en orden ascendente.'
    );
    assertConfirmationPersistence(
        $payments->orderIdsMatchCheckout($paymentId, $checkoutId),
        'Payment y Checkout no recuperaron el mismo conjunto de Orders.'
    );

    $otherSessionId = $sessions->create([
        'public_id' => $otherSessionPublicId,
        'checkout_id' => $checkoutId,
        'payment_id' => null,
        'idempotency_key' => 'b1-key-other-' . $nonce,
        'request_fingerprint' => hash('sha256', 'other-' . $nonce),
        'status' => 'pending',
        'provider' => null,
        'provider_session_id' => null,
        'redirect_url' => null,
        'currency' => 'CLP',
        'amount' => '3000.00',
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);

    try {
        $wpdb->suppress_errors(true);
        $sessions->linkPayment($otherSessionId, $paymentId);
        throw new RuntimeException('Un Payment fue vinculado a dos sesiones.');
    } catch (PersistenceException) {
    } finally {
        $wpdb->suppress_errors(false);
    }

    $fingerprint = PaymentConfirmationFingerprint::make([
        'provider' => 'webpay_plus',
        'payment_session_id' => $sessionId,
        'payment_id' => $paymentId,
        'checkout_id' => $checkoutId,
        'order_ids' => $orderIds,
        'amount' => 3000,
        'currency' => 'CLP',
        'buy_order' => 'VA' . str_repeat('A', 24),
        'financial_session_id' => 'VA-' . str_repeat('B', 58),
        'safe_financial_reference' => 'sha256:abcdef123456',
        'transaction_date' => '2026-07-12T10:05:00Z',
    ]);
    $audit = new PaymentConfirmationAudit(
        $correlationId,
        PaymentConfirmationAudit::EVENT_STARTED,
        $sessionId,
        $paymentId,
        $checkoutId,
        $fingerprint,
        PaymentConfirmationFingerprint::VERSION,
        'webpay_plus',
        '3000.00',
        'CLP',
        'ready',
        'ready',
        'confirmation_started',
        'info',
        1,
        'sha256:abcdef123456',
        $orderIds,
        ['origin' => 'test', 'lock_order' => 'session:payment:orders'],
        $now
    );
    assertConfirmationPersistence(
        $audits->insert($audit) > 0
        && count($audits->findByCorrelationId($correlationId)) === 1
        && count($audits->findByPaymentSessionId($sessionId)) === 1
        && count($audits->findByFingerprint($fingerprint)) === 1,
        'No se recupero la auditoria funcional.'
    );
    $confirmedAt = gmdate('Y-m-d H:i:s');
    $sessions->storeConfirmationEvidence(
        $sessionId,
        $paymentId,
        $fingerprint,
        PaymentConfirmationFingerprint::VERSION,
        'sha256:abcdef123456',
        $confirmedAt
    );
    $stored = $sessions->find($sessionId);
    assertConfirmationPersistence(
        ($stored['status'] ?? null) === 'confirmed'
        && ($stored['confirmed_at'] ?? null) === $confirmedAt,
        'No se guardo evidencia confirmada en el fixture.'
    );

    throw new RuntimeException('force_test_rollback');
} catch (RuntimeException $exception) {
    $wpdb->query('ROLLBACK');

    if ($exception->getMessage() !== 'force_test_rollback') {
        throw $exception;
    }
}

assertConfirmationPersistence(
    $sessions->findByPaymentId($paymentId) === null
    && $audits->findByCorrelationId($correlationId) === [],
    'Rollback no revirtio sesion y auditoria.'
);

echo "PASS payment-confirmation-persistence-integration-test\n";
