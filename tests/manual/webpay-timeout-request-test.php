<?php

declare(strict_types=1);

use VeciAhorra\Modules\Payments\Requests\WebpayReturnRequest;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

function assertWebpayTimeoutRequest(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$token = str_repeat('T', 64);
$buyOrder = 'VA' . str_repeat('A', 24);
$sessionId = 'VA-' . str_repeat('B', 58);

$commit = WebpayReturnRequest::fromArray(['token_ws' => $token], 'POST');
assertWebpayTimeoutRequest(
    $commit->flow === 'commit' && $commit->token === $token,
    'El retorno token_ws historico cambio.'
);

$abort = WebpayReturnRequest::fromArray([
    'TBK_TOKEN' => $token,
    'TBK_ORDEN_COMPRA' => $buyOrder,
    'TBK_ID_SESION' => $sessionId,
], 'POST');
assertWebpayTimeoutRequest(
    $abort->flow === 'abort'
        && $abort->buyOrder === $buyOrder
        && $abort->sessionId === $sessionId,
    'El retorno TBK_TOKEN legitimo cambio.'
);

$timeout = WebpayReturnRequest::fromArray([
    'TBK_ORDEN_COMPRA' => $buyOrder,
    'TBK_ID_SESION' => $sessionId,
], 'GET');
assertWebpayTimeoutRequest(
    $timeout->flow === 'timeout'
        && $timeout->token === null
        && $timeout->buyOrder === $buyOrder
        && $timeout->sessionId === $sessionId,
    'El timeout oficial no fue normalizado.'
);

$invalid = [
    [[], 'GET'],
    [['TBK_ORDEN_COMPRA' => $buyOrder], 'GET'],
    [['TBK_ID_SESION' => $sessionId], 'GET'],
    [[
        'TBK_ORDEN_COMPRA' => $buyOrder,
        'TBK_ID_SESION' => $sessionId,
    ], 'POST'],
    [[
        'token_ws' => $token,
        'TBK_TOKEN' => $token,
    ], 'POST'],
    [[
        'token_ws' => $token,
        'TBK_ORDEN_COMPRA' => $buyOrder,
        'TBK_ID_SESION' => $sessionId,
    ], 'GET'],
    [[
        'TBK_ORDEN_COMPRA' => [$buyOrder, $buyOrder],
        'TBK_ID_SESION' => $sessionId,
    ], 'GET'],
    [[
        'TBK_ORDEN_COMPRA' => $buyOrder,
        'TBK_ID_SESION' => [$sessionId, $sessionId],
    ], 'GET'],
    [[
        'TBK_ORDEN_COMPRA' => 'VA' . str_repeat('A', 23) . '!',
        'TBK_ID_SESION' => $sessionId,
    ], 'GET'],
    [[
        'TBK_ORDEN_COMPRA' => $buyOrder,
        'TBK_ID_SESION' => $sessionId . "\n",
    ], 'GET'],
];

foreach ($invalid as [$payload, $method]) {
    try {
        WebpayReturnRequest::fromArray($payload, $method);
        throw new RuntimeException('Se acepto un retorno Webpay invalido.');
    } catch (InvalidArgumentException) {
    }
}

echo "PASS webpay-timeout-request-test assertions=13\n";
