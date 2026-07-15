<?php

declare(strict_types=1);

use VeciAhorra\Modules\Payments\Repository\PaymentRepository;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\PaymentSessionContext;
use VeciAhorra\Modules\Payments\Gateway\GatewaySessionResult;
use VeciAhorra\Modules\Payments\Service\PaymentSessionService;

putenv('webpay_environment=integration');
putenv('webpay_commerce_code=597055555532');
putenv('webpay_api_key=' . str_repeat('A', 32));
putenv('webpay_return_url=https://example.test/webpay/return');
require_once dirname(__DIR__, 5) . '/wp-load.php';

[$script, $checkoutId, $idempotencyKey, $userId, $counter] = array_pad($argv, 5, null);

if (! is_string($checkoutId) || ! is_string($idempotencyKey)) {
    fwrite(STDERR, "Argumentos invalidos.\n");
    exit(2);
}

final class ConcurrentWebpayCreateFake implements PaymentGatewayInterface
{
    public function __construct(private string $counter) {}
    public function createSession(PaymentSessionContext $context): GatewaySessionResult
    {
        $handle = fopen($this->counter, 'c+');
        flock($handle, LOCK_EX);
        $current = (int) trim((string) stream_get_contents($handle));
        ftruncate($handle, 0); rewind($handle); fwrite($handle, (string) ($current + 1)); fflush($handle);
        flock($handle, LOCK_UN); fclose($handle);
        usleep(300000);
        return new GatewaySessionResult('webpay_plus', substr(hash('sha256', $context->paymentSessionId), 0, 40),
            GatewaySessionResult::STATUS_READY,
            'https://webpay3gint.transbank.cl/webpayserver/initTransaction', $context->expiresAt);
    }
    public function recoverSession(string $providerSessionId): GatewaySessionResult
    { throw new RuntimeException('Recovery remoto inesperado.'); }
}
$service = new PaymentSessionService(new PaymentRepository(), new ConcurrentWebpayCreateFake((string) $counter));
$result = $service->start(
    $checkoutId,
    $idempotencyKey,
    ['user_id' => (int) $userId, 'session_id' => null]
);

echo wp_json_encode($result);
