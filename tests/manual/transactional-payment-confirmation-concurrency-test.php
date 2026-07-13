<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Payments\Gateway\WebpayTransactionReference;
use VeciAhorra\Modules\Payments\Models\PaymentConfirmationAudit;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertTransactionalConcurrency(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function runTransactionalWorkers(array $payloads): array
{
    $worker = __DIR__ . '/transactional-payment-confirmation-worker.php';
    $descriptors = [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ];
    $processes = [];

    foreach ($payloads as $payload) {
        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            $worker,
            base64_encode((string) wp_json_encode($payload)),
        ], $descriptors, $pipes);
        assertTransactionalConcurrency(is_resource($process), 'No inicio worker B2.');
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }

    $results = [];

    foreach ($processes as [$process, $pipes]) {
        $stdout = trim((string) stream_get_contents($pipes[1]));
        $stderr = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        assertTransactionalConcurrency(
            $exit === 0 && $stderr === '',
            'Worker B2 fallo: ' . $stderr
        );
        $results[] = $stdout;
    }

    sort($results);

    return $results;
}

global $wpdb;

$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$created = [];
$makeFixture = static function () use ($wpdb, $prefix, &$created): array {
    $seed = bin2hex(random_bytes(8));
    $now = current_time('mysql');
    $expires = gmdate('Y-m-d H:i:s', time() + 3600);
    $productId = random_int(930000000, 939999999);
    $wpdb->insert($prefix . 'inventory', [
        'product_id' => $productId, 'minimarket_id' => 940000001,
        'price' => '1000.00', 'stock' => 0, 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $inventoryId = (int) $wpdb->insert_id;
    $wpdb->insert($prefix . 'orders', [
        'customer_id' => 990020, 'minimarket_id' => 940000001,
        'total' => '1000.00', 'status' => 'reserved',
        'reservation_expires_at' => $expires,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $orderId = (int) $wpdb->insert_id;
    $wpdb->insert($prefix . 'order_items', [
        'order_id' => $orderId, 'product_id' => $productId,
        'inventory_id' => $inventoryId, 'quantity' => 1,
        'unit_price' => '1000.00', 'subtotal' => '1000.00',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $itemId = (int) $wpdb->insert_id;
    $wpdb->insert($prefix . 'reservations', [
        'order_id' => $orderId, 'inventory_id' => $inventoryId,
        'product_id' => $productId, 'minimarket_id' => 940000001,
        'quantity' => 1, 'status' => 'active', 'reserved_at' => $now,
        'expires_at' => $expires, 'released_at' => null,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $reservationId = (int) $wpdb->insert_id;
    $checkoutPublicId = 'chk_' . substr(hash('sha256', $seed), 0, 43);
    $wpdb->insert($prefix . 'checkouts', [
        'public_id' => $checkoutPublicId, 'owner_type' => 'user',
        'user_id' => 990020, 'session_id' => null,
        'status' => 'payment_started', 'currency' => 'CLP',
        'total_amount' => '1000.00', 'created_at' => $now,
        'updated_at' => $now, 'expires_at' => $expires,
    ]);
    $checkoutId = (int) $wpdb->insert_id;
    $wpdb->insert($prefix . 'checkout_orders', [
        'checkout_id' => $checkoutId, 'order_id' => $orderId,
        'created_at' => $now,
    ]);
    $checkoutOrderId = (int) $wpdb->insert_id;
    $wpdb->insert($prefix . 'payments', [
        'payment_reference' => 'PAY-B2-C-' . strtoupper($seed),
        'customer_id' => 990020, 'amount' => '1000.00',
        'currency' => 'CLP', 'status' => 'pending',
        'provider' => 'webpay_plus', 'provider_reference' => null,
        'expires_at' => $expires, 'paid_at' => null,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $paymentId = (int) $wpdb->insert_id;
    $wpdb->insert($prefix . 'payment_orders', [
        'payment_id' => $paymentId, 'order_id' => $orderId,
        'created_at' => $now,
    ]);
    $paymentOrderId = (int) $wpdb->insert_id;
    $token = strtoupper(hash('sha256', 'token-' . $seed));
    $tokenHash = hash('sha256', $token);
    $key = 'b2-concurrency-' . $seed;
    $wpdb->insert($prefix . 'payment_sessions', [
        'public_id' => 'ps_' . substr(hash('sha256', 'ps-' . $seed), 0, 43),
        'checkout_id' => $checkoutId, 'payment_id' => $paymentId,
        'idempotency_key' => $key,
        'request_fingerprint' => hash('sha256', $seed),
        'status' => 'ready', 'provider' => 'webpay_plus',
        'provider_session_id' => $token, 'redirect_url' => null,
        'currency' => 'CLP', 'amount' => '1000.00', 'metadata' => null,
        'created_at' => $now, 'updated_at' => $now, 'expires_at' => $expires,
    ]);
    $sessionId = (int) $wpdb->insert_id;
    $wpdb->insert($prefix . 'webpay_returns', [
        'token_hash' => $tokenHash, 'payment_session_id' => $sessionId,
        'flow' => 'commit', 'processing_status' => 'completed',
        'result_status' => 'approved', 'result_json' => '{}',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $returnId = (int) $wpdb->insert_id;
    $created[] = compact(
        'inventoryId', 'orderId', 'itemId', 'reservationId', 'checkoutId',
        'checkoutOrderId', 'paymentId', 'paymentOrderId', 'sessionId', 'returnId'
    );

    return [
        'provider' => 'webpay_plus', 'status' => 'AUTHORIZED',
        'responseCode' => 0, 'amount' => 1000, 'currency' => 'CLP',
        'buyOrder' => WebpayTransactionReference::buyOrder($checkoutPublicId, $key),
        'financialSessionId' => WebpayTransactionReference::sessionId($checkoutPublicId),
        'transactionDate' => '2026-07-12T10:05:00Z',
        'safeFinancialReference' => 'sha256:' . substr($tokenHash, 0, 12),
        'tokenHash' => $tokenHash, 'paymentTypeCode' => 'VD',
        'correlationId' => 'corr_' . substr(hash('sha256', $seed), 0, 24),
        'origin' => 'test',
    ];
};

try {
    $same = $makeFixture();
    assertTransactionalConcurrency(
        runTransactionalWorkers([$same, $same])
            === ['already_confirmed', 'confirmed'],
        'Concurrencia identica no fue idempotente.'
    );
    $sessionId = $created[0]['sessionId'];
    assertTransactionalConcurrency(
        (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}payment_confirmation_audits"
            . " WHERE payment_session_id = %d AND event_type = %s",
            $sessionId,
            PaymentConfirmationAudit::EVENT_SUCCEEDED
        )) === 1,
        'Concurrencia creo mas de una auditoria de exito.'
    );

    $first = $makeFixture();
    $different = [...$first, 'transactionDate' => '2026-07-12T10:06:00Z'];
    assertTransactionalConcurrency(
        runTransactionalWorkers([$first, $different])
            === ['confirmed', 'idempotency_conflict'],
        'Concurrencia conflictiva no fue rechazada.'
    );

    $locked = $makeFixture();
    $lockedFixture = $created[array_key_last($created)];
    $readyFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'va-confirmation-lock-' . bin2hex(random_bytes(6));
    $holderPipes = [];
    $holder = proc_open([
        PHP_BINARY,
        __DIR__ . '/transactional-payment-confirmation-lock-holder.php',
        (string) $lockedFixture['sessionId'],
        $readyFile,
    ], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $holderPipes);
    assertTransactionalConcurrency(is_resource($holder), 'No inicio lock holder.');
    fclose($holderPipes[0]);

    for ($attempt = 0; $attempt < 100 && ! is_file($readyFile); $attempt++) {
        usleep(20000);
    }

    assertTransactionalConcurrency(is_file($readyFile), 'Lock holder no bloqueo Session.');
    $startedAt = microtime(true);
    $lockResults = runTransactionalWorkers([[
        ...$locked,
        '_lockWaitTimeout' => 1,
    ]]);
    $elapsed = microtime(true) - $startedAt;
    $holderStdout = stream_get_contents($holderPipes[1]);
    $holderStderr = stream_get_contents($holderPipes[2]);
    fclose($holderPipes[1]);
    fclose($holderPipes[2]);
    $holderExit = proc_close($holder);

    if (is_file($readyFile)) {
        unlink($readyFile);
    }

    assertTransactionalConcurrency(
        $lockResults === ['lock_timeout']
        && $elapsed >= 1.8
        && $holderExit === 0
        && trim((string) $holderStdout) === ''
        && trim((string) $holderStderr) === '',
        'Lock timeout real no fue clasificado o limitado: '
            . wp_json_encode([
                'results' => $lockResults,
                'elapsed' => $elapsed,
                'holder_exit' => $holderExit,
                'holder_stdout' => trim((string) $holderStdout),
                'holder_stderr' => trim((string) $holderStderr),
            ])
    );
    assertTransactionalConcurrency(
        $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$prefix}payment_sessions WHERE id = %d",
            $lockedFixture['sessionId']
        )) === 'ready',
        'Lock timeout modifico PaymentSession.'
    );

    echo "PASS transactional-payment-confirmation-concurrency-test\n";
} finally {
    foreach (array_reverse($created) as $f) {
        $wpdb->delete($prefix . 'payment_confirmation_audits', [
            'payment_session_id' => $f['sessionId'],
        ]);
        foreach ([
            'webpay_returns' => 'returnId', 'payment_sessions' => 'sessionId',
            'payment_orders' => 'paymentOrderId', 'payments' => 'paymentId',
            'checkout_orders' => 'checkoutOrderId', 'checkouts' => 'checkoutId',
            'reservations' => 'reservationId', 'order_items' => 'itemId',
            'orders' => 'orderId', 'inventory' => 'inventoryId',
        ] as $table => $field) {
            $wpdb->delete($prefix . $table, ['id' => $f[$field]]);
        }
    }
}
