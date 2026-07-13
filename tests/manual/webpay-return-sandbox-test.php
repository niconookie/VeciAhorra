<?php

declare(strict_types=1);

use VeciAhorra\Core\Application;
use VeciAhorra\Modules\Payments\Requests\WebpayReturnRequest;
use VeciAhorra\Modules\Payments\Service\WebpayReturnService;

if (getenv('VECIAHORRA_RUN_WEBPAY_RETURN_SMOKE') !== '1') {
    fwrite(STDERR, "NOT RUN: define VECIAHORRA_RUN_WEBPAY_RETURN_SMOKE=1.\n");
    exit(2);
}

$token = getenv('VECIAHORRA_WEBPAY_RETURN_TOKEN');

if (! is_string($token) || $token === '') {
    fwrite(STDERR, "NOT RUN: falta VECIAHORRA_WEBPAY_RETURN_TOKEN.\n");
    fwrite(STDERR, "Cree la sesion mediante POST /veciahorra/v1/payments/session,\n");
    fwrite(STDERR, "complete el pago sandbox y copie token_ws a esa variable local.\n");
    exit(2);
}

require_once dirname(__DIR__, 5) . '/wp-load.php';

try {
    $service = (new Application())->container()->make(
        WebpayReturnService::class
    );
    $result = $service->process(WebpayReturnRequest::fromArray([
        'token_ws' => $token,
    ]));
    $data = $result->toArray();

    if (! in_array($result->result, [
        'approved', 'rejected', 'already_processed',
    ], true)) {
        throw new RuntimeException(
            'El retorno real no produjo un resultado financiero coherente.'
        );
    }

    if (
        str_contains((string) wp_json_encode($data), $token)
        || ($data['business_state_updated'] ?? true) !== false
    ) {
        throw new RuntimeException('La respuesta expuso datos sensibles.');
    }

    echo "Webpay return sandbox: PASS\n";
    echo 'Result: ' . $result->result . "\n";
    echo 'Token reference: ' . $result->tokenReference . "\n";
    echo "Business state updated: false\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Webpay return sandbox: FAIL\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
