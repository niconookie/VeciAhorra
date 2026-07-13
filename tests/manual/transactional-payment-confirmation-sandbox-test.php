<?php

declare(strict_types=1);

use VeciAhorra\Core\Application;
use VeciAhorra\Modules\Payments\Models\NormalizedFinancialApproval;
use VeciAhorra\Modules\Payments\Service\TransactionalPaymentConfirmationService;

if (getenv('VECIAHORRA_RUN_PAYMENT_CONFIRMATION_SMOKE') !== '1') {
    fwrite(STDERR, "NOT RUN: habilite VECIAHORRA_RUN_PAYMENT_CONFIRMATION_SMOKE=1.\n");
    exit(2);
}

$json = getenv('VECIAHORRA_NORMALIZED_FINANCIAL_RESULT');
$data = is_string($json) ? json_decode($json, true) : null;

if (! is_array($data)) {
    fwrite(STDERR, "NOT RUN: falta el resultado financiero normalizado local.\n");
    exit(2);
}

require_once dirname(__DIR__, 5) . '/wp-load.php';

try {
    $financial = new NormalizedFinancialApproval(...$data);
    $result = (new Application())->container()->make(
        TransactionalPaymentConfirmationService::class
    )->confirm($financial);

    if (! in_array($result->code, ['confirmed', 'already_confirmed'], true)) {
        throw new RuntimeException(
            'La confirmacion sandbox no termino en un estado confirmado.'
        );
    }

    echo "Transactional payment confirmation sandbox: PASS\n";
    echo 'Result: ' . $result->code . "\n";
    echo 'Correlation ID: ' . $result->correlationId . "\n";
    echo 'Fingerprint reference: '
        . (string) $result->fingerprintReference . "\n";
    echo "Delivery created: false\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Transactional payment confirmation sandbox: FAIL\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
