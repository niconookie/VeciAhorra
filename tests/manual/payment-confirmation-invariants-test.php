<?php

declare(strict_types=1);

use VeciAhorra\Modules\Payments\Models\PaymentConfirmationAudit;
use VeciAhorra\Modules\Payments\Models\PaymentSession;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;
use VeciAhorra\Modules\Payments\Support\PaymentConfirmationFingerprint;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertConfirmationInvariant(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$baseSession = [
    'id' => 1,
    'public_id' => 'ps_' . str_repeat('A', 43),
    'checkout_id' => 2,
    'idempotency_key' => 'idempotency-key-0001',
    'request_fingerprint' => str_repeat('a', 64),
    'status' => 'ready',
    'provider' => 'webpay_plus',
    'provider_session_id' => str_repeat('T', 64),
    'redirect_url' => 'https://example.test/pay',
    'currency' => 'CLP',
    'amount' => '1000.00',
    'metadata' => null,
    'created_at' => '2026-07-12 10:00:00',
    'updated_at' => '2026-07-12 10:00:00',
    'expires_at' => '2026-07-12 10:15:00',
    'payment_id' => null,
    'confirmation_fingerprint' => null,
    'confirmation_fingerprint_version' => null,
    'safe_financial_reference' => null,
    'confirmed_at' => null,
];

foreach (['pending', 'ready', 'expired', 'cancelled'] as $status) {
    assertConfirmationInvariant(
        PaymentSession::fromArray([...$baseSession, 'status' => $status])
            ->status === $status,
        "No se acepto estado {$status}."
    );
}

$fingerprintData = [
    'provider' => 'webpay_plus',
    'payment_session_id' => 1,
    'payment_id' => 5,
    'checkout_id' => 2,
    'order_ids' => [9, 7],
    'amount' => 1000,
    'currency' => 'CLP',
    'buy_order' => 'VA' . str_repeat('A', 24),
    'financial_session_id' => 'VA-' . str_repeat('B', 58),
    'safe_financial_reference' => 'sha256:abcdef123456',
    'transaction_date' => '2026-07-12T10:05:00Z',
];
$fingerprint = PaymentConfirmationFingerprint::make($fingerprintData);
assertConfirmationInvariant(
    strlen($fingerprint) === 64
    && ctype_xdigit($fingerprint)
    && PaymentConfirmationFingerprint::matches(
        $fingerprint,
        PaymentConfirmationFingerprint::make([
            ...$fingerprintData,
            'order_ids' => [7, 9],
        ])
    ),
    'El fingerprint no es determinista.'
);
assertConfirmationInvariant(
    $fingerprint
        === 'e416b0fc3c6c45600c51c2ed92e97b5b30194d933af61e42c2753e0e3a3ce890',
    'El vector conocido del fingerprint cambio.'
);
$confirmed = PaymentSession::fromArray([
    ...$baseSession,
    'status' => 'confirmed',
    'payment_id' => 5,
    'confirmation_fingerprint' => $fingerprint,
    'confirmation_fingerprint_version' => 1,
    'safe_financial_reference' => 'sha256:abcdef123456',
    'confirmed_at' => '2026-07-12 10:06:00',
]);
assertConfirmationInvariant(
    $confirmed->status === PaymentSession::STATUS_CONFIRMED
    && $confirmed->confirmedAt !== null,
    'No se construyo sesion confirmada coherente.'
);

foreach ([
    [...$baseSession, 'status' => 'unknown'],
    [...$baseSession, 'status' => 'confirmed', 'confirmed_at' => null],
    [...$baseSession, 'confirmed_at' => '2026-07-12 10:06:00'],
    [...$baseSession, 'confirmation_fingerprint' => 'invalid'],
    [...$baseSession, 'confirmation_fingerprint' => $fingerprint,
        'confirmation_fingerprint_version' => 0],
    [...$baseSession, 'payment_id' => true],
] as $invalidSession) {
    try {
        PaymentSession::fromArray($invalidSession);
        throw new RuntimeException('PaymentSession invalida aceptada.');
    } catch (InvalidArgumentException) {
    }
}

foreach ([
    [...$fingerprintData, 'amount' => 1000.5],
    [...$fingerprintData, 'payment_id' => '5'],
    [...$fingerprintData, 'order_ids' => [7, true]],
    [...$fingerprintData, 'currency' => ' CLP'],
    [...$fingerprintData, 'safe_financial_reference' => str_repeat('T', 64)],
] as $invalidFingerprint) {
    try {
        PaymentConfirmationFingerprint::make($invalidFingerprint);
        throw new RuntimeException('Fingerprint invalido aceptado.');
    } catch (InvalidArgumentException) {
    }
}

$audit = new PaymentConfirmationAudit(
    'corr_abcdefghijklmnop',
    PaymentConfirmationAudit::EVENT_STARTED,
    1,
    5,
    2,
    $fingerprint,
    1,
    'webpay_plus',
    '1000.00',
    'CLP',
    'ready',
    'ready',
    'confirmation_started',
    'info',
    1,
    'sha256:abcdef123456',
    [7, 9],
    ['origin' => 'test', 'lock_order' => 'session:payment:orders'],
    '2026-07-12 10:05:00'
);
$persisted = wp_json_encode($audit->toPersistence());
$exampleToken = str_repeat('SENSITIVE', 8);
assertConfirmationInvariant(
    is_string($persisted)
    && ! str_contains($persisted, $exampleToken)
    && ! str_contains(strtolower($persisted), 'token_ws'),
    'La auditoria expuso un token.'
);

try {
    new PaymentConfirmationAudit(
        'corr_abcdefghijklmnop',
        PaymentConfirmationAudit::EVENT_STARTED,
        1,
        5,
        2,
        $fingerprint,
        1,
        'webpay_plus',
        '1000.00',
        'CLP',
        'ready',
        'ready',
        'confirmation_started',
        'info',
        1,
        'sha256:abcdef123456',
        [7, 9],
        ['origin' => $exampleToken],
        '2026-07-12 10:05:00'
    );
    throw new RuntimeException('Auditoria sensible aceptada.');
} catch (InvalidArgumentException $exception) {
    assertConfirmationInvariant(
        ! str_contains($exception->getMessage(), $exampleToken),
        'La excepcion expuso el token.'
    );
}

try {
    (new PaymentSessionRepository())->updateGatewayResult(
        1,
        PaymentSession::STATUS_CONFIRMED,
        PaymentSession::STATUS_READY,
        'webpay_plus',
        str_repeat('T', 64),
        null,
        '2026-07-12 10:15:00',
        '2026-07-12 10:06:00'
    );
    throw new RuntimeException('Una sesion confirmed fue reabierta.');
} catch (InvalidArgumentException) {
}

echo "PASS payment-confirmation-invariants-test\n";
