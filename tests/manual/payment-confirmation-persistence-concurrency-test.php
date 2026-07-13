<?php

declare(strict_types=1);

use VeciAhorra\Core\Config;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertConfirmationConcurrency(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function runConfirmationLinkWorkers(array $pairs): array
{
    $worker = __DIR__ . '/payment-confirmation-link-worker.php';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $processes = [];

    foreach ($pairs as [$sessionId, $paymentId]) {
        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            $worker,
            (string) $sessionId,
            (string) $paymentId,
        ], $descriptors, $pipes);
        assertConfirmationConcurrency(
            is_resource($process),
            'No se inicio worker de concurrencia.'
        );
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
        assertConfirmationConcurrency(
            $exit === 0 && $stderr === '',
            'Worker de concurrencia fallo: ' . $stderr
        );
        $results[] = $stdout;
    }

    sort($results);

    return $results;
}

global $wpdb;

$prefix = $wpdb->prefix . Config::TABLE_PREFIX;
$now = current_time('mysql');
$nonce = bin2hex(random_bytes(8));
$checkoutIds = [];
$paymentIds = [];
$sessionIds = [];

try {
    $wpdb->insert($prefix . 'checkouts', [
        'public_id' => 'chk_' . substr(hash('sha256', $nonce), 0, 43),
        'owner_type' => 'user',
        'user_id' => 991001,
        'session_id' => null,
        'status' => 'payment_started',
        'currency' => 'CLP',
        'total_amount' => '1000.00',
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $checkoutIds[] = (int) $wpdb->insert_id;
    $checkoutId = $checkoutIds[0];

    $createPayment = static function (string $reference) use (
        $wpdb,
        $prefix,
        $now,
        &$paymentIds
    ): int {
        $wpdb->insert($prefix . 'payments', [
            'payment_reference' => $reference,
            'customer_id' => 991001,
            'amount' => '1000.00',
            'currency' => 'CLP',
            'status' => 'pending',
            'provider' => null,
            'provider_reference' => null,
            'expires_at' => null,
            'paid_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $wpdb->insert_id;
        $paymentIds[] = $id;

        return $id;
    };
    $createSession = static function (string $seed) use (
        $wpdb,
        $prefix,
        $checkoutId,
        $now,
        &$sessionIds
    ): int {
        $wpdb->insert($prefix . 'payment_sessions', [
            'public_id' => 'ps_' . substr(hash('sha256', $seed), 0, 43),
            'checkout_id' => $checkoutId,
            'payment_id' => null,
            'idempotency_key' => 'concurrency-' . $seed,
            'request_fingerprint' => hash('sha256', $seed),
            'status' => 'pending',
            'provider' => null,
            'provider_session_id' => null,
            'redirect_url' => null,
            'currency' => 'CLP',
            'amount' => '1000.00',
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        $id = (int) $wpdb->insert_id;
        $sessionIds[] = $id;

        return $id;
    };

    $sharedSession = $createSession($nonce . '-shared-session');
    $paymentOne = $createPayment('PAY-B1-C1-' . strtoupper($nonce));
    $paymentTwo = $createPayment('PAY-B1-C2-' . strtoupper($nonce));
    assertConfirmationConcurrency(
        runConfirmationLinkWorkers([
            [$sharedSession, $paymentOne],
            [$sharedSession, $paymentTwo],
        ]) === ['conflict', 'linked'],
        'Dos Payments ganaron la misma PaymentSession.'
    );

    $sharedPayment = $createPayment('PAY-B1-C3-' . strtoupper($nonce));
    $sessionOne = $createSession($nonce . '-session-one');
    $sessionTwo = $createSession($nonce . '-session-two');
    assertConfirmationConcurrency(
        runConfirmationLinkWorkers([
            [$sessionOne, $sharedPayment],
            [$sessionTwo, $sharedPayment],
        ]) === ['conflict', 'linked'],
        'Un Payment fue vinculado a dos PaymentSessions.'
    );

    echo "PASS payment-confirmation-persistence-concurrency-test\n";
} finally {
    foreach ($sessionIds as $id) {
        $wpdb->delete($prefix . 'payment_sessions', ['id' => $id]);
    }

    foreach ($paymentIds as $id) {
        $wpdb->delete($prefix . 'payments', ['id' => $id]);
    }

    foreach ($checkoutIds as $id) {
        $wpdb->delete($prefix . 'checkouts', ['id' => $id]);
    }
}
