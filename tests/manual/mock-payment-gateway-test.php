<?php

declare(strict_types=1);

use VeciAhorra\Core\Application;
use VeciAhorra\Modules\Payments\Gateway\GatewaySessionResult;
use VeciAhorra\Modules\Payments\Gateway\MockPaymentGateway;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\PaymentSessionContext;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertMockGateway(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertMockGatewaySame(mixed $expected, mixed $actual): void
{
    assertMockGateway(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

$now = new DateTimeImmutable('2026-07-11 12:00:00', wp_timezone());
$context = new PaymentSessionContext(
    'ps_test_session',
    'chk_test_checkout',
    '15000.00',
    'CLP',
    '2026-07-11 12:30:00',
    'mock-gateway-idempotency-key-0001'
);
$gateway = new MockPaymentGateway(MockPaymentGateway::SCENARIO_SUCCESS, $now);
assertMockGateway(
    $gateway instanceof PaymentGatewayInterface,
    'MockPaymentGateway no implementa PaymentGatewayInterface.'
);
$success = $gateway->createSession($context);
$successReplay = $gateway->createSession($context);
assertMockGatewaySame(GatewaySessionResult::STATUS_READY, $success->status);
assertMockGatewaySame('mock', $success->provider);
assertMockGatewaySame('2026-07-11 12:15:00', $success->expiresAt);
assertMockGatewaySame(
    $success->providerSessionId,
    $successReplay->providerSessionId
);
assertMockGatewaySame(
    $success->providerSessionId,
    $gateway->recoverSession($success->providerSessionId)->providerSessionId
);
assertMockGateway(
    is_string($success->redirectUrl)
        && str_contains($success->redirectUrl, '/veciahorra/mock-payment/'),
    'El escenario exitoso no genero la URL Mock controlada.'
);

try {
    $gateway->createSession(new PaymentSessionContext(
        'ps_other_session',
        'chk_test_checkout',
        '16000.00',
        'CLP',
        '2026-07-11 12:30:00',
        'mock-gateway-idempotency-key-0001'
    ));
    throw new RuntimeException('Se esperaba conflicto interno de idempotencia.');
} catch (InvalidArgumentException) {
}

$rejected = (new MockPaymentGateway(
    MockPaymentGateway::SCENARIO_REJECTED,
    $now
))->createSession(new PaymentSessionContext(
    'ps_rejected',
    'chk_rejected',
    '15000.00',
    'CLP',
    '2026-07-11 12:30:00',
    'mock-gateway-idempotency-key-0002'
));
assertMockGatewaySame(GatewaySessionResult::STATUS_REJECTED, $rejected->status);
assertMockGatewaySame('mock_rejected', $rejected->errorCode);
assertMockGatewaySame(null, $rejected->redirectUrl);

$expired = (new MockPaymentGateway(
    MockPaymentGateway::SCENARIO_EXPIRED,
    $now
))->createSession(new PaymentSessionContext(
    'ps_expired',
    'chk_expired',
    '15000.00',
    'CLP',
    '2026-07-11 12:30:00',
    'mock-gateway-idempotency-key-0003'
));
assertMockGatewaySame(GatewaySessionResult::STATUS_EXPIRED, $expired->status);
assertMockGatewaySame('mock_expired', $expired->errorCode);
assertMockGateway($expired->expiresAt < '2026-07-11 12:00:00', 'No expiro.');

$resolved = (new Application())->container()->make(
    PaymentGatewayInterface::class
);
assertMockGateway(
    $resolved instanceof MockPaymentGateway,
    'El contenedor no inyecto Mock a traves de la interfaz.'
);
$serviceSource = (string) file_get_contents(
    dirname(__DIR__, 2)
        . '/app/Modules/Payments/Service/PaymentSessionService.php'
);
assertMockGateway(
    str_contains($serviceSource, 'PaymentGatewayInterface')
        && ! str_contains($serviceSource, 'MockPaymentGateway')
        && ! str_contains($serviceSource, 'DummyPaymentGateway'),
    'PaymentSessionService conoce una implementacion concreta.'
);

echo "PASS mock-payment-gateway-test\n";
