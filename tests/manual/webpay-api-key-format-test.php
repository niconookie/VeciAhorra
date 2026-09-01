<?php

declare(strict_types=1);

use Transbank\Webpay\WebpayPlus\Transaction;
use VeciAhorra\Modules\Payments\Gateway\WebpayGatewayConfiguration;
use VeciAhorra\Modules\Payments\Gateway\WebpayPaymentGateway;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

function assertWebpayApiKey(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function webpayConfiguration(string $apiKey): WebpayGatewayConfiguration
{
    return new WebpayGatewayConfiguration(
        'production',
        '597055555555',
        $apiKey,
        'https://example.test/wp-json/veciahorra/v1/payments/webpay/return'
    );
}

$validApiKeys = [
    str_repeat('HistoricalKey123', 2) . 'ABCD',
    '01234567-89ab-cdef-0123-456789abcdef',
    'ABCDEF01-2345-6789-ABCD-EF0123456789',
];

foreach ($validApiKeys as $apiKey) {
    $configuration = webpayConfiguration(' ' . $apiKey . ' ');
    assertWebpayApiKey(
        $configuration->apiKey() === $apiKey,
        'La API Key valida no se conservo intacta despues del trim exterior.'
    );
}

$uuid = $validApiKeys[1];
$gateway = new WebpayPaymentGateway(webpayConfiguration($uuid));
$transactionMethod = new ReflectionMethod($gateway, 'transaction');
$transaction = $transactionMethod->invoke($gateway);
assertWebpayApiKey(
    $transaction instanceof Transaction
        && $transaction->getOptions()->getApiKey() === $uuid,
    'La API Key UUID no llego intacta a la configuracion del SDK.'
);

$invalidApiKeys = [
    '',
    'too-short',
    '01234567-89ab-cdef-0123-456789abcde',
    '01234567-89ab-cdef-0123-456789abcdef0',
    '0123457-89ab-cdef-0123-456789abcdef',
    '012345678-89ab-cdef-0123-456789abcdef',
    '01234567-89ab-cdef-0123-456789abcdeg',
    '{01234567-89ab-cdef-0123-456789abcdef}',
    '01234567-89ab-cdef-01 3-456789abcdef',
    "01234567-89ab-cdef-0123-456789ab\ncdef",
    "01234567-89ab-cdef-0123-456789ab\0cdef",
    '01234567-89ab-cdef-0123--456789abcdef',
    '0123456789ab-cdef-0123-456789abcdef',
    '01234567_89ab-cdef-0123-456789abcdef',
    str_repeat('A', 31),
    str_repeat('A', 257),
    str_repeat('A', 31) . '-',
];

foreach ($invalidApiKeys as $apiKey) {
    try {
        webpayConfiguration($apiKey);
        throw new RuntimeException('Se acepto un formato de API Key invalido.');
    } catch (InvalidArgumentException $exception) {
        assertWebpayApiKey(
            $exception->getMessage() === 'La API Key Webpay no es valida.',
            'La excepcion de validacion no fue generica.'
        );
    }
}

echo "Webpay API Key format: OK\n";
