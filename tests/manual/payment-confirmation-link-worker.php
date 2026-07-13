<?php

declare(strict_types=1);

use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

[$script, $sessionId, $paymentId] = array_pad($argv, 3, null);

if (! ctype_digit((string) $sessionId) || ! ctype_digit((string) $paymentId)) {
    fwrite(STDERR, "Argumentos invalidos.\n");
    exit(2);
}

global $wpdb;

$wpdb->query('SET SESSION innodb_lock_wait_timeout = 5');
$wpdb->query('START TRANSACTION');

try {
    $wpdb->suppress_errors(true);
    (new PaymentSessionRepository())->linkPayment(
        (int) $sessionId,
        (int) $paymentId
    );
    $wpdb->query('COMMIT');
    $wpdb->suppress_errors(false);
    echo 'linked';
} catch (PersistenceException) {
    $wpdb->query('ROLLBACK');
    $wpdb->suppress_errors(false);
    echo 'conflict';
}
