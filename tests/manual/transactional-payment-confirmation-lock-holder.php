<?php

declare(strict_types=1);

use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;

require_once dirname(__DIR__, 5) . '/wp-load.php';

$sessionId = $argv[1] ?? null;
$readyFile = $argv[2] ?? null;

if (! ctype_digit((string) $sessionId) || ! is_string($readyFile)) {
    fwrite(STDERR, "Argumentos invalidos.\n");
    exit(2);
}

global $wpdb;

$wpdb->query('START TRANSACTION');
(new PaymentSessionRepository())->findForUpdate((int) $sessionId);
file_put_contents($readyFile, 'locked', LOCK_EX);
sleep(10);
$wpdb->query('ROLLBACK');
