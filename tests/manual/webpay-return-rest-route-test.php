<?php

declare(strict_types=1);

use VeciAhorra\Modules\Payments\Controller\PaymentController;
use VeciAhorra\Modules\Payments\Controller\WebpayReturnController;
use VeciAhorra\Modules\Payments\Gateway\WebpayCommitResult;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\WebpayTransactionReference;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;
use VeciAhorra\Modules\Payments\Repository\WebpayReturnRepository;
use VeciAhorra\Modules\Payments\Routes\PaymentRoutes;
use VeciAhorra\Modules\Payments\Service\WebpayReturnService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertWebpayRestRoute(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

final class RestRouteGateway implements WebpayReturnGatewayInterface
{
    public int $commits = 0;

    public function __construct(private WebpayCommitResult $result)
    {
    }

    public function commit(string $token): WebpayCommitResult
    {
        $this->commits++;

        return $this->result;
    }
}

final class RestRouteSessions extends PaymentSessionRepository
{
    public function __construct(private array $session)
    {
    }

    public function findByProviderSessionId(string $providerSessionId): ?array
    {
        return $this->session;
    }
}

final class RestRouteReturns extends WebpayReturnRepository
{
    private array $rows = [];

    public function __construct()
    {
    }

    public function claim(
        string $tokenHash,
        ?int $paymentSessionId,
        string $flow,
        string $now
    ): array {
        if (isset($this->rows[$tokenHash])) {
            return ['claimed' => false, 'row' => $this->rows[$tokenHash]];
        }

        $this->rows[$tokenHash] = [
            'payment_session_id' => $paymentSessionId,
            'processing_status' => 'processing',
            'result_status' => null,
            'result_json' => null,
        ];

        return ['claimed' => true, 'row' => null];
    }

    public function complete(
        string $tokenHash,
        string $resultStatus,
        array $result,
        string $now
    ): void {
        $this->rows[$tokenHash]['processing_status'] = 'completed';
        $this->rows[$tokenHash]['result_status'] = $resultStatus;
        $this->rows[$tokenHash]['result_json'] = wp_json_encode($result);
    }

    public function fail(string $tokenHash, string $now): void
    {
        $this->rows[$tokenHash]['processing_status'] = 'retryable';
    }

    public function retry(string $tokenHash, string $now): bool
    {
        return false;
    }
}

$checkoutId = 'chk_route_abcdefghijklmnopqrstuvwxyz012345';
$idempotencyKey = 'route-idempotency-key-0001';
$buyOrder = WebpayTransactionReference::buyOrder(
    $checkoutId,
    $idempotencyKey
);
$sessionId = WebpayTransactionReference::sessionId($checkoutId);
$session = [
    'id' => 701,
    'checkout_public_id' => $checkoutId,
    'idempotency_key' => $idempotencyKey,
    'amount' => '1000.00',
];
$gateway = new RestRouteGateway(new WebpayCommitResult(
    'AUTHORIZED',
    0,
    1000,
    $buyOrder,
    $sessionId,
    null,
    null,
    null,
    null,
    null,
    null,
    null
));
$service = new WebpayReturnService(
    $gateway,
    new RestRouteSessions($session),
    new RestRouteReturns()
);
$controller = new WebpayReturnController($service);
$paymentController = (new ReflectionClass(PaymentController::class))
    ->newInstanceWithoutConstructor();
$routes = new PaymentRoutes($paymentController, $controller);

// Use an isolated REST server so the request cannot reach production repositories.
$GLOBALS['wp_rest_server'] = new WP_REST_Server();
remove_all_actions('rest_api_init');
add_action('rest_api_init', [$routes, 'register']);
do_action('rest_api_init', $GLOBALS['wp_rest_server']);

$route = '/veciahorra/v1/payments/webpay/return';

function webpayRouteRequest(
    string $method,
    array $query = [],
    array $body = [],
    string $path = '/veciahorra/v1/payments/webpay/return'
): WP_REST_Response {
    $request = new WP_REST_Request($method, $path);
    $request->set_query_params($query);

    if ($body !== []) {
        $request->set_header(
            'Content-Type',
            'application/x-www-form-urlencoded'
        );
        $request->set_body(http_build_query($body));
        // PHP populates $_POST before WordPress constructs the REST request.
        $request->set_body_params($body);
    }

    return rest_do_request($request);
}

function assertSafeWebpayResponse(
    WP_REST_Response $response,
    array $tokens
): array {
    $data = $response->get_data();
    $encoded = wp_json_encode($data);

    assertWebpayRestRoute(is_array($data), 'Respuesta REST Webpay invalida.');
    assertWebpayRestRoute(is_string($encoded), 'Respuesta Webpay no serializable.');

    foreach ($tokens as $token) {
        assertWebpayRestRoute(
            ! str_contains($encoded, $token),
            'La respuesta expuso un token Webpay completo.'
        );
    }

    return $data;
}

function assertResult(
    WP_REST_Response $response,
    string $expected,
    array $tokens
): void {
    $data = assertSafeWebpayResponse($response, $tokens);

    assertWebpayRestRoute(
        $response->get_status() === 200
            && ($data['data']['result'] ?? null) === $expected,
        "Resultado REST Webpay inesperado; se esperaba {$expected}."
    );
}

// GET uses query only. A conflicting body token must be ignored.
$getToken = str_repeat('G', 64);
$get = webpayRouteRequest(
    'GET',
    ['token_ws' => $getToken],
    ['TBK_TOKEN' => str_repeat('X', 64)]
);
assertResult($get, 'approved', [$getToken]);
assertWebpayRestRoute($gateway->commits === 1, 'GET no delego commit una vez.');

$getRepeated = webpayRouteRequest('GET', ['token_ws' => $getToken]);
assertResult($getRepeated, 'already_processed', [$getToken]);
assertWebpayRestRoute($gateway->commits === 1, 'GET repetido duplico commit.');

$getThenPost = webpayRouteRequest('POST', [], ['token_ws' => $getToken]);
assertResult($getThenPost, 'already_processed', [$getToken]);
assertWebpayRestRoute($gateway->commits === 1, 'GET seguido de POST duplico commit.');

// POST uses body only. A conflicting query token must be ignored.
$postToken = str_repeat('P', 64);
$post = webpayRouteRequest(
    'POST',
    ['TBK_TOKEN' => str_repeat('Q', 64)],
    ['token_ws' => $postToken]
);
assertResult($post, 'approved', [$postToken]);
assertWebpayRestRoute($gateway->commits === 2, 'POST no delego commit una vez.');

$postThenGet = webpayRouteRequest('GET', ['token_ws' => $postToken]);
assertResult($postThenGet, 'already_processed', [$postToken]);
assertWebpayRestRoute($gateway->commits === 2, 'POST seguido de GET duplico commit.');

// Abort is normalized by the same service and never commits.
$abortGetToken = str_repeat('A', 64);
$abortGet = webpayRouteRequest('GET', [
    'TBK_TOKEN' => $abortGetToken,
    'TBK_ORDEN_COMPRA' => $buyOrder,
    'TBK_ID_SESION' => $sessionId,
]);
assertResult($abortGet, 'aborted', [$abortGetToken]);
assertWebpayRestRoute($gateway->commits === 2, 'Aborto GET ejecuto commit.');

$abortGetOnlyToken = str_repeat('E', 64);
$abortGetOnly = webpayRouteRequest('GET', [
    'TBK_TOKEN' => $abortGetOnlyToken,
]);
assertResult($abortGetOnly, 'aborted', [$abortGetOnlyToken]);
assertWebpayRestRoute(
    $gateway->commits === 2,
    'Aborto GET sin referencias ejecuto commit.'
);

$abortPostToken = str_repeat('B', 64);
$abortPost = webpayRouteRequest('POST', [], [
    'TBK_TOKEN' => $abortPostToken,
    'TBK_ORDEN_COMPRA' => $buyOrder,
    'TBK_ID_SESION' => $sessionId,
]);
assertResult($abortPost, 'aborted', [$abortPostToken]);
assertWebpayRestRoute($gateway->commits === 2, 'Aborto POST ejecuto commit.');

foreach ([
    ['GET', [], []],
    ['POST', [], []],
    ['GET', ['token_ws' => ''], []],
    ['GET', ['token_ws' => ['invalid']], []],
    ['POST', [], ['TBK_TOKEN' => ['invalid']]],
    ['GET', [
        'token_ws' => str_repeat('C', 64),
        'TBK_TOKEN' => str_repeat('D', 64),
    ], []],
    ['POST', [], [
        'token_ws' => str_repeat('H', 64),
        'TBK_TOKEN' => str_repeat('I', 64),
    ]],
] as [$method, $query, $body]) {
    $invalid = webpayRouteRequest($method, $query, $body);
    $invalidData = assertSafeWebpayResponse($invalid, array_values(array_filter([
        is_string($query['token_ws'] ?? null) ? $query['token_ws'] : null,
        is_string($query['TBK_TOKEN'] ?? null) ? $query['TBK_TOKEN'] : null,
        is_string($body['token_ws'] ?? null) ? $body['token_ws'] : null,
        is_string($body['TBK_TOKEN'] ?? null) ? $body['TBK_TOKEN'] : null,
    ])));
    assertWebpayRestRoute(
        $invalid->get_status() === 400
            && ($invalidData['error']['code'] ?? null) === 'invalid_webpay_return',
        'Retorno Webpay anomalo aceptado.'
    );
}
assertWebpayRestRoute($gateway->commits === 2, 'Retorno anomalo ejecuto commit.');

// Own logs may classify ambiguity but must never include either token.
$logFile = tempnam(sys_get_temp_dir(), 'va-webpay-route-');
assertWebpayRestRoute(is_string($logFile), 'No se creo log temporal.');
$previousLog = ini_get('error_log');
ini_set('log_errors', '1');
ini_set('error_log', $logFile);
$logTokenA = str_repeat('L', 64);
$logTokenB = str_repeat('M', 64);
webpayRouteRequest('GET', [
    'token_ws' => $logTokenA,
    'TBK_TOKEN' => $logTokenB,
]);
$logged = (string) file_get_contents($logFile);
ini_set('error_log', is_string($previousLog) ? $previousLog : '');
unlink($logFile);
assertWebpayRestRoute(
    ! str_contains($logged, $logTokenA)
        && ! str_contains($logged, $logTokenB),
    'Un log propio expuso el token Webpay.'
);

$wrong = webpayRouteRequest(
    'POST',
    [],
    [],
    '/veciahorra/v1/payments/webpay/incorrect'
);
$wrongData = $wrong->get_data();
assertWebpayRestRoute(
    $wrong->get_status() === 404
        && is_array($wrongData)
        && ($wrongData['code'] ?? null) === 'rest_no_route',
    'Una ruta Webpay incorrecta no devolvio 404.'
);

foreach (['PUT', 'DELETE'] as $method) {
    $unsupported = webpayRouteRequest($method);
    $unsupportedData = $unsupported->get_data();
    assertWebpayRestRoute(
        $unsupported->get_status() === 404
            && is_array($unsupportedData)
            && ($unsupportedData['code'] ?? null) === 'rest_no_route',
        "El metodo {$method} fue aceptado por la ruta Webpay."
    );
}

$root = dirname(__DIR__, 2);
$source = implode("\n", array_map(
    static fn (string $path): string => (string) file_get_contents($root . $path),
    [
        '/app/Modules/Payments/Routes/PaymentRoutes.php',
        '/app/Modules/Payments/Controller/WebpayReturnController.php',
        '/app/Modules/Payments/Service/WebpayReturnService.php',
    ]
));
foreach ([
    'wc_get_order(',
    'payment_complete(',
    'update_status(',
    'reduce_order_stock(',
    'wc_reduce_stock_levels(',
    'add_order_note(',
    'new Delivery(',
    'new Reservation(',
] as $forbidden) {
    assertWebpayRestRoute(
        ! str_contains($source, $forbidden),
        'La ruta de retorno modifica WC_Order.'
    );
}

echo "PASS webpay-return-rest-route-test\n";
