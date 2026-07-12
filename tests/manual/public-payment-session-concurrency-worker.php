<?php

declare(strict_types=1);

use VeciAhorra\Core\Application;
use VeciAhorra\Modules\Payments\Service\PaymentSessionService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

[$script, $checkoutId, $idempotencyKey, $userId] = array_pad($argv, 4, null);

if (! is_string($checkoutId) || ! is_string($idempotencyKey)) {
    fwrite(STDERR, "Argumentos invalidos.\n");
    exit(2);
}

$service = (new Application())->container()->make(PaymentSessionService::class);
$result = $service->start(
    $checkoutId,
    $idempotencyKey,
    ['user_id' => (int) $userId, 'session_id' => null]
);

echo wp_json_encode($result);
