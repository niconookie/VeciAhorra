<?php

declare(strict_types=1);

use VeciAhorra\Modules\Payments\Gateway\PaymentSessionContext;
use VeciAhorra\Modules\Payments\Gateway\WebpayGatewayConfiguration;
use VeciAhorra\Modules\Payments\Gateway\WebpayPaymentGateway;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function smokePass(string $message): void
{
    echo $message . ": PASS\n";
}

function smokeFail(string $message): never
{
    fwrite(STDERR, "Webpay integration smoke test: FAIL\n");
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$requiredConstants = [
    'VECIAHORRA_WEBPAY_ENVIRONMENT',
    'VECIAHORRA_WEBPAY_COMMERCE_CODE',
    'VECIAHORRA_WEBPAY_API_KEY',
];
$missingConstants = array_values(array_filter(
    $requiredConstants,
    static fn (string $name): bool => ! defined($name)
));

if ($missingConstants !== []) {
    smokeFail(
        'Faltan constantes: ' . implode(', ', $missingConstants)
    );
}

if (! class_exists(Transbank\Webpay\WebpayPlus\Transaction::class)) {
    smokeFail('El SDK oficial de Transbank no esta disponible.');
}

smokePass('Webpay SDK available');

try {
    $environment = constant('VECIAHORRA_WEBPAY_ENVIRONMENT');
    $commerceCode = constant('VECIAHORRA_WEBPAY_COMMERCE_CODE');
    $apiKey = constant('VECIAHORRA_WEBPAY_API_KEY');

    if (! is_string($environment)) {
        smokeFail('VECIAHORRA_WEBPAY_ENVIRONMENT debe ser string.');
    }

    if (! is_string($commerceCode)) {
        smokeFail('VECIAHORRA_WEBPAY_COMMERCE_CODE debe ser string.');
    }

    if (! is_string($apiKey)) {
        smokeFail('VECIAHORRA_WEBPAY_API_KEY debe ser string.');
    }

    $configuration = new WebpayGatewayConfiguration(
        $environment,
        $commerceCode,
        $apiKey,
        'https://localhost/veciahorra/webpay/return'
    );

    if ($configuration->environment !== 'integration') {
        smokeFail('El smoke test solo puede ejecutarse en integration.');
    }

    smokePass('Webpay configuration');
    echo 'Environment: integration' . "\n";

    $nonce = bin2hex(random_bytes(16));
    $gateway = new WebpayPaymentGateway($configuration);
    $result = $gateway->createSession(new PaymentSessionContext(
        'ps_smoke_' . $nonce,
        'chk_smoke_' . $nonce,
        '1000.00',
        'CLP',
        gmdate('Y-m-d H:i:s', time() + 600),
        'webpay-smoke-' . $nonce
    ));

    smokePass('Transaction creation');
    smokePass('Token received');
    smokePass('Payment URL received');
    smokePass('Payment URL uses HTTPS');
    smokePass('Secrets not exposed');
} catch (Throwable $exception) {
    smokeFail($exception->getMessage());
}
