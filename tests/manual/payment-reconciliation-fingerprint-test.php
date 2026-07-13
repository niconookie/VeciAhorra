<?php

declare(strict_types=1);

use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\FinancialFingerprintComponents;
use VeciAhorra\Modules\Payments\Reconciliation\Support\FinancialFingerprint;
use VeciAhorra\Modules\Payments\Reconciliation\Support\ReconciliationValidation;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

function assertReconciliationFingerprint(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function fingerprintComponents(
    int $amount = 15990,
    string $buyOrder = 'VA1234567890ABCDEF12345678',
    string $sessionId = 'VA-1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890'
): FinancialFingerprintComponents
{
    return new FinancialFingerprintComponents(
        'integration',
        hash('sha256', 'commerce-code-test'),
        ' authorized ',
        0,
        $amount,
        $buyOrder,
        $sessionId,
        '2026-07-13T16:30:00Z',
        'bdf05cc6b29d9fad586d6e24f75e5a72206edeb9f34a39f080a1d58a932f6c3d',
        'vd',
        0,
        '0713'
    );
}

$first = fingerprintComponents();
$second = fingerprintComponents();
$fingerprint = FinancialFingerprint::make($first);

assertReconciliationFingerprint(
    $fingerprint === FinancialFingerprint::make($first)
    && $fingerprint === FinancialFingerprint::make($second),
    'La misma entrada reconstruida no produjo el mismo fingerprint.'
);
assertReconciliationFingerprint(
    preg_match('/^[a-f0-9]{64}$/D', $fingerprint) === 1,
    'El fingerprint no posee formato SHA-256 hexadecimal.'
);
assertReconciliationFingerprint(
    $first->providerStatus() === 'AUTHORIZED'
    && $first->paymentTypeCode() === 'VD',
    'La normalizacion de campos irrelevantes no fue estable.'
);
assertReconciliationFingerprint(
    $fingerprint !== FinancialFingerprint::make(fingerprintComponents(15991))
    && $fingerprint !== FinancialFingerprint::make(
        fingerprintComponents(15990, 'VA1234567890ABCDEF12345679')
    )
    && $fingerprint !== FinancialFingerprint::make(
        fingerprintComponents(
            15990,
            'VA1234567890ABCDEF12345678',
            'VA-2234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890'
        )
    ),
    'Una diferencia financiera material no altero el fingerprint.'
);

$reordered = new FinancialFingerprintComponents(
    accountingDate: '0713',
    installmentsNumber: 0,
    paymentTypeCode: 'VD',
    authorizationHash: 'bdf05cc6b29d9fad586d6e24f75e5a72206edeb9f34a39f080a1d58a932f6c3d',
    transactionDate: '2026-07-13T16:30:00Z',
    financialSessionId: 'VA-1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890',
    buyOrder: 'VA1234567890ABCDEF12345678',
    amountClp: 15990,
    responseCode: 0,
    providerStatus: 'AUTHORIZED',
    merchantIdentityHash: hash('sha256', 'commerce-code-test'),
    environment: 'integration'
);
assertReconciliationFingerprint(
    FinancialFingerprint::make($reordered) === $fingerprint,
    'El orden de entrada altero el fingerprint canonico.'
);

$offset = new FinancialFingerprintComponents(
    'integration',
    'a246c4e356f7546bd96484a273c20a65ad5dd39bd9541109eb7c522b4194c5e3',
    'AUTHORIZED',
    0,
    15990,
    'VA1234567890ABCDEF12345678',
    'VA-1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890',
    '2026-07-13T12:30:00-04:00',
    'bdf05cc6b29d9fad586d6e24f75e5a72206edeb9f34a39f080a1d58a932f6c3d',
    'VD',
    0,
    '0713'
);
assertReconciliationFingerprint(
    $offset->transactionDate() === '2026-07-13T16:30:00Z'
    && FinancialFingerprint::make($offset) === $fingerprint,
    'Offsets ISO-8601 equivalentes no convergieron a UTC.'
);

$ordered = $first->canonicalData();
$expectedKeys = [
    'schema', 'provider', 'environment', 'merchant_identity_hash',
    'provider_status', 'response_code', 'amount_clp', 'currency',
    'buy_order', 'financial_session_id', 'transaction_date',
    'authorization_hash', 'payment_type_code', 'installments_number',
    'accounting_date',
];
assertReconciliationFingerprint(
    array_keys($ordered) === $expectedKeys,
    'La serializacion canonica no respeta el orden normativo.'
);

foreach ([1500.0, '1500.00', '1500', NAN, INF, true, [], new stdClass()] as $invalid) {
    try {
        new FinancialFingerprintComponents(
            'integration', hash('sha256', 'merchant'), 'AUTHORIZED', 0,
            $invalid, 'VA123', 'SESSION1', null, null, null, null, null
        );
        throw new RuntimeException('CLP acepto un valor no entero.');
    } catch (InvalidArgumentException|TypeError) {
    }
}

$originA = new DurablePaymentOrigin(
    'poc_' . str_repeat('a', 32), 'site-1', 'woocommerce', '100',
    'veciahorra_webpay_plus', 'attempt-aaaaaaaa', 15990, 'integration',
    hash('sha256', 'commerce-code-test'), 'VA1234567890ABCDEF12345678',
    'VA-1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890',
    str_repeat('1', 64), 1,
    '2026-07-13 16:00:00', '2026-07-13 16:00:00', '2026-07-13 17:00:00'
);
$originB = new DurablePaymentOrigin(
    'poc_' . str_repeat('b', 32), 'site-1', 'woocommerce', '100',
    'veciahorra_webpay_plus', 'attempt-bbbbbbbb', 15990, 'integration',
    hash('sha256', 'commerce-code-test'), 'VA1234567890ABCDEF12345678',
    'VA-1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890',
    str_repeat('2', 64), 1,
    '2026-07-13 16:00:00', '2026-07-13 16:00:00', '2026-07-13 17:00:00'
);
assertReconciliationFingerprint(
    $originA->originKey() !== $originB->originKey()
    && FinancialFingerprint::make($first) === $fingerprint,
    'El intento debe cambiar originKeyV1, no la evidencia financiera.'
);

$material = FinancialFingerprint::canonicalJson($first)
    . json_encode($originA->originKey());
assertReconciliationFingerprint(
    FinancialFingerprint::canonicalJson($first) ===
        '{"schema":"webpay-financial-v1","provider":"webpay_plus",'
        . '"environment":"integration","merchant_identity_hash":'
        . '"a246c4e356f7546bd96484a273c20a65ad5dd39bd9541109eb7c522b4194c5e3",'
        . '"provider_status":"AUTHORIZED","response_code":0,'
        . '"amount_clp":15990,"currency":"CLP","buy_order":'
        . '"VA1234567890ABCDEF12345678","financial_session_id":'
        . '"VA-1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890",'
        . '"transaction_date":"2026-07-13T16:30:00Z",'
        . '"authorization_hash":'
        . '"bdf05cc6b29d9fad586d6e24f75e5a72206edeb9f34a39f080a1d58a932f6c3d",'
        . '"payment_type_code":"VD","installments_number":0,'
        . '"accounting_date":"0713"}'
    && $fingerprint ===
        'c5b5be2b6f8cb7469613cf0111901e3de15e1d0078706290d743080352e5567f'
    && $originA->originKey() ===
        'b78fff3818fdb148359df0587e487f3be61a63054f5b31a29562c6d84603d6a8',
    'Los vectores absolutos V1 cambiaron.'
);

foreach (['merchant_identity_hash', 'authorization_hash', 'token_hash'] as $field) {
    foreach ([str_repeat('a', 63), str_repeat('a', 65), str_repeat('A', 64)] as $invalidHash) {
        try {
            ReconciliationValidation::hash($invalidHash, $field);
            throw new RuntimeException("{$field} acepto un hash no canonico.");
        } catch (InvalidArgumentException) {
        }
    }
}
assertReconciliationFingerprint(
    ! str_contains($material, 'token_ws')
    && ! str_contains($material, 'authorization-code'),
    'El material seguro contiene token o codigo de autorizacion completo.'
);

echo "PASS payment-reconciliation-fingerprint-test\n";
