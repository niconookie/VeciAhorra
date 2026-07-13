<?php

declare(strict_types=1);

use VeciAhorra\Core\Application;
use VeciAhorra\Modules\Payments\Models\NormalizedFinancialApproval;
use VeciAhorra\Modules\Payments\Service\TransactionalPaymentConfirmationService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

$encoded = $argv[1] ?? null;
$json = is_string($encoded) ? base64_decode($encoded, true) : false;
$data = is_string($json) ? json_decode($json, true) : null;

if (! is_array($data)) {
    fwrite(STDERR, "Entrada financiera invalida.\n");
    exit(2);
}

$lockWaitTimeout = $data['_lockWaitTimeout'] ?? null;
unset($data['_lockWaitTimeout']);

$financial = new NormalizedFinancialApproval(...$data);
$service = (new Application())->container()->make(
    TransactionalPaymentConfirmationService::class
);

if (is_int($lockWaitTimeout) && $lockWaitTimeout > 0) {
    global $wpdb;

    $configured = $wpdb->query($wpdb->prepare(
        'SET SESSION innodb_lock_wait_timeout = %d',
        $lockWaitTimeout
    ));

    if (
        $configured === false
        || (int) $wpdb->get_var(
            'SELECT @@SESSION.innodb_lock_wait_timeout'
        ) !== $lockWaitTimeout
    ) {
        fwrite(STDERR, "No fue posible configurar lock wait timeout.\n");
        exit(3);
    }

    $wpdb->suppress_errors(true);
}

$result = $service->confirm($financial);

if (isset($wpdb) && is_int($lockWaitTimeout) && $lockWaitTimeout > 0) {
    $wpdb->suppress_errors(false);
}

echo $result->code;
