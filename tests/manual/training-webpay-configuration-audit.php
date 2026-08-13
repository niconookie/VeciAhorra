<?php

declare(strict_types=1);

require dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayConfiguration;

$result = ['gateway' => PaymentGatewayConfiguration::gateway()];

try {
    $configuration = PaymentGatewayConfiguration::webpay();
    $parts = parse_url($configuration->returnUrl);
    $result['webpay'] = [
        'valid' => true,
        'environment' => $configuration->environment,
        'commerce_code_configured' => $configuration->commerceCode !== '',
        'api_key_configured' => $configuration->apiKey() !== '',
        'return_url' => [
            'scheme' => $parts['scheme'] ?? null,
            'host' => $parts['host'] ?? null,
            'path' => $parts['path'] ?? null,
        ],
    ];
} catch (Throwable $error) {
    $result['webpay'] = [
        'valid' => false,
        'error_class' => $error::class,
        'error' => $error->getMessage(),
    ];
}

echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
