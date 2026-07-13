<?php

declare(strict_types=1);

use VeciAhorra\Modules\Payments\Models\NormalizedFinancialApproval;
use VeciAhorra\Modules\Payments\Models\TransactionalPaymentConfirmationResult;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertTransactionalUnit(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$valid = [
    'provider' => 'webpay_plus',
    'status' => 'AUTHORIZED',
    'responseCode' => 0,
    'amount' => 3000,
    'currency' => 'CLP',
    'buyOrder' => 'VA' . str_repeat('A', 24),
    'financialSessionId' => 'VA-' . str_repeat('B', 58),
    'transactionDate' => '2026-07-12T10:05:00Z',
    'safeFinancialReference' => 'sha256:abcdef123456',
    'tokenHash' => str_repeat('a', 64),
    'paymentTypeCode' => 'VD',
    'correlationId' => 'corr_abcdefghijklmnop',
    'origin' => 'test',
];
$approval = new NormalizedFinancialApproval(...$valid);
assertTransactionalUnit($approval->isApproved(), 'Aprobacion valida rechazada.');

foreach ([
    ['provider' => 'stripe'],
    ['amount' => 0],
    ['currency' => 'USD'],
    ['buyOrder' => ''],
    ['financialSessionId' => ''],
    ['safeFinancialReference' => str_repeat('T', 64)],
    ['tokenHash' => 'invalid'],
    ['correlationId' => 'short'],
] as $replacement) {
    try {
        new NormalizedFinancialApproval(...array_replace($valid, $replacement));
        throw new RuntimeException('Resultado financiero invalido aceptado.');
    } catch (InvalidArgumentException) {
    }
}

foreach ([
    ['amount' => true],
    ['amount' => 1000.5],
    ['responseCode' => '0'],
] as $replacement) {
    try {
        new NormalizedFinancialApproval(...array_replace($valid, $replacement));
        throw new RuntimeException('Tipo financiero invalido aceptado.');
    } catch (TypeError) {
    }
}

$rejected = new NormalizedFinancialApproval(...array_replace($valid, [
    'status' => 'FAILED',
    'responseCode' => -1,
]));
assertTransactionalUnit(! $rejected->isApproved(), 'Rechazo se considero aprobado.');

foreach ([
    'session_not_found', 'payment_not_found', 'checkout_not_found',
    'orders_not_found', 'relationship_mismatch', 'order_set_mismatch',
    'amount_mismatch', 'currency_mismatch', 'buy_order_mismatch',
    'session_identifier_mismatch', 'provider_mismatch',
    'reservation_expired', 'invalid_state', 'idempotency_conflict',
    'lock_timeout', 'deadlock', 'transient_database_error',
    'permanent_database_error', 'commit_ambiguous',
    'partial_inconsistency', 'unexpected_error',
] as $code) {
    $result = TransactionalPaymentConfirmationResult::failure(
        $code,
        $valid['correlationId'],
        in_array($code, ['lock_timeout', 'deadlock'], true)
    );
    assertTransactionalUnit(
        $result->code === $code
        && ! $result->success
        && ! str_contains($result->message, $valid['tokenHash']),
        "Resultado interno incorrecto: {$code}."
    );
}

echo "PASS transactional-payment-confirmation-unit-test\n";
