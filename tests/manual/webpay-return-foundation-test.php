<?php

declare(strict_types=1);

use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayException;
use VeciAhorra\Modules\Payments\Gateway\WebpayCommitResult;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnContext;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnContextRepositoryInterface;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnGatewayResolverInterface;
use VeciAhorra\Modules\Payments\Gateway\WebpayTransactionReference;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;
use VeciAhorra\Modules\Payments\Repository\WebpayReturnRepository;
use VeciAhorra\Modules\Payments\Requests\WebpayReturnRequest;
use VeciAhorra\Modules\Payments\Service\WebpayReturnService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertWebpayReturn(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

final class FakeWebpayReturnGateway implements WebpayReturnGatewayInterface
{
    public int $commits = 0;
    public ?PaymentGatewayException $exception = null;

    public function __construct(public WebpayCommitResult $result)
    {
    }

    public function commit(string $token): WebpayCommitResult
    {
        $this->commits++;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->result;
    }
}

final class FakeWebpayReturnContexts implements
    WebpayReturnContextRepositoryInterface
{
    public array $contexts = [];
    public int $forgets = 0;

    public function store(
        string $tokenHash,
        WebpayReturnContext $context,
        int $ttl
    ): void {
        $this->contexts[$tokenHash] = $context;
    }

    public function find(string $tokenHash): ?WebpayReturnContext
    {
        return $this->contexts[$tokenHash] ?? null;
    }

    public function forget(string $tokenHash): void
    {
        $this->forgets++;
        unset($this->contexts[$tokenHash]);
    }
}

final class FakeWebpayReturnGatewayResolver implements
    WebpayReturnGatewayResolverInterface
{
    public int $resolutions = 0;

    public function __construct(private WebpayReturnGatewayInterface $gateway)
    {
    }

    public function resolve(
        ?WebpayReturnContext $context
    ): WebpayReturnGatewayInterface {
        assertWebpayReturn(
            $context?->source === WebpayReturnContext::SOURCE_WOOCOMMERCE,
            'El resolver no recibio el contexto WooCommerce.'
        );
        $this->resolutions++;

        return $this->gateway;
    }
}

final class FakeReturnPaymentSessions extends PaymentSessionRepository
{
    public function __construct(private ?array $session)
    {
    }

    public function findByProviderSessionId(string $providerSessionId): ?array
    {
        return $this->session;
    }
}

final class FakeWebpayReturns extends WebpayReturnRepository
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
            'token_hash' => $tokenHash,
            'payment_session_id' => $paymentSessionId,
            'flow' => $flow,
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
        $this->rows[$tokenHash]['result_json'] = json_encode($result);
    }

    public function fail(string $tokenHash, string $now): void
    {
        $this->rows[$tokenHash]['processing_status'] = 'retryable';
    }

    public function retry(string $tokenHash, string $now): bool
    {
        if (($this->rows[$tokenHash]['processing_status'] ?? null) !== 'retryable') {
            return false;
        }

        $this->rows[$tokenHash]['processing_status'] = 'processing';

        return true;
    }

    public function seedProcessing(string $token): void
    {
        $this->rows[hash('sha256', $token)] = [
            'payment_session_id' => 91,
            'processing_status' => 'processing',
            'result_status' => null,
            'result_json' => null,
        ];
    }
}

$token = str_repeat('T', 64);
$checkout = 'chk_abcdefghijklmnopqrstuvwxyz0123456789ABCDEF';
$key = 'return-idempotency-key-0001';
$buyOrder = WebpayTransactionReference::buyOrder($checkout, $key);
$sessionId = WebpayTransactionReference::sessionId($checkout);
$session = [
    'id' => 91,
    'checkout_public_id' => $checkout,
    'idempotency_key' => $key,
    'amount' => '1000.00',
];
$approved = new WebpayCommitResult(
    'AUTHORIZED', 0, 1000, $buyOrder, $sessionId, 'AUTH', 'VD', 0,
    '0712', '2026-07-12T20:30:00Z', '6623', 0
);
$gateway = new FakeWebpayReturnGateway($approved);
$returns = new FakeWebpayReturns();
$service = new WebpayReturnService(
    $gateway,
    new FakeReturnPaymentSessions($session),
    $returns
);
$result = $service->process(WebpayReturnRequest::fromArray(['token_ws' => $token]));
assertWebpayReturn($result->result === 'approved', 'No aprobo retorno coherente.');
assertWebpayReturn($gateway->commits === 1, 'commit no se ejecuto una vez.');
assertWebpayReturn(! str_contains(json_encode($result->toArray()), $token), 'Token expuesto.');
$repeated = $service->process(WebpayReturnRequest::fromArray(['token_ws' => $token]));
assertWebpayReturn($repeated->result === 'already_processed', 'No fue idempotente.');
assertWebpayReturn($gateway->commits === 1, 'Retorno repetido ejecuto commit.');

$rejectedGateway = new FakeWebpayReturnGateway(new WebpayCommitResult(
    'FAILED', -1, 1000, $buyOrder, $sessionId, null, 'VD', 0,
    null, null, null, null
));
$rejected = (new WebpayReturnService(
    $rejectedGateway,
    new FakeReturnPaymentSessions($session),
    new FakeWebpayReturns()
))->process(WebpayReturnRequest::fromArray(['token_ws' => str_repeat('R', 64)]));
assertWebpayReturn($rejected->result === 'rejected', 'Rechazo financiero incorrecto.');

foreach ([999, 1001, 0, -1] as $amount) {
    $inconsistentGateway = new FakeWebpayReturnGateway(new WebpayCommitResult(
        'AUTHORIZED', 0, $amount, $buyOrder, $sessionId, null, null, null,
        null, null, null, null
    ));
    $inconsistent = (new WebpayReturnService(
        $inconsistentGateway,
        new FakeReturnPaymentSessions($session),
        new FakeWebpayReturns()
    ))->process(WebpayReturnRequest::fromArray([
        'token_ws' => str_repeat(chr(65 + ($amount & 15)), 64),
    ]));
    assertWebpayReturn($inconsistent->result === 'inconsistent', 'Monto inconsistente aceptado.');
}

$abortGateway = new FakeWebpayReturnGateway($approved);
$abortService = new WebpayReturnService(
    $abortGateway,
    new FakeReturnPaymentSessions($session),
    new FakeWebpayReturns()
);
$aborted = $abortService->process(WebpayReturnRequest::fromArray([
    'TBK_TOKEN' => str_repeat('A', 64),
    'TBK_ORDEN_COMPRA' => $buyOrder,
    'TBK_ID_SESION' => $sessionId,
]));
assertWebpayReturn($aborted->result === 'aborted', 'Aborto no normalizado.');
assertWebpayReturn($abortGateway->commits === 0, 'Aborto ejecuto commit.');
$abortRepeated = $abortService->process(WebpayReturnRequest::fromArray([
    'TBK_TOKEN' => str_repeat('A', 64),
]));
assertWebpayReturn(
    $abortRepeated->result === 'already_processed'
    && $abortGateway->commits === 0,
    'Aborto repetido no fue idempotente.'
);
$abortMismatch = (new WebpayReturnService(
    $abortGateway,
    new FakeReturnPaymentSessions($session),
    new FakeWebpayReturns()
))->process(WebpayReturnRequest::fromArray([
    'TBK_TOKEN' => str_repeat('B', 64),
    'TBK_ORDEN_COMPRA' => 'VA' . str_repeat('F', 24),
]));
assertWebpayReturn(
    $abortMismatch->result === 'inconsistent',
    'Aborto inconsistente fue aceptado.'
);

foreach ([
    [],
    ['token_ws' => $token, 'TBK_TOKEN' => $token],
    ['token_ws' => ''],
    ['token_ws' => []],
    ['token_ws' => str_repeat('X', 192)],
    ['token_ws' => "validtokenvalue12\n"],
    ['arbitrary' => 'value'],
] as $invalid) {
    try {
        WebpayReturnRequest::fromArray($invalid);
        throw new RuntimeException('Solicitud invalida aceptada.');
    } catch (InvalidArgumentException) {
    }
}

$gatewayError = new FakeWebpayReturnGateway($approved);
$gatewayError->exception = new PaymentGatewayException(
    'No fue posible conectar con Webpay.',
    'webpay_connection_error'
);
$retryableReturns = new FakeWebpayReturns();
$retryableService = new WebpayReturnService(
    $gatewayError,
    new FakeReturnPaymentSessions($session),
    $retryableReturns
);
$failed = $retryableService->process(WebpayReturnRequest::fromArray([
    'token_ws' => str_repeat('E', 64),
]));
assertWebpayReturn($failed->result === 'gateway_error', 'Error tecnico mal clasificado.');
$gatewayError->exception = null;
$recovered = $retryableService->process(WebpayReturnRequest::fromArray([
    'token_ws' => str_repeat('E', 64),
]));
assertWebpayReturn(
    $recovered->result === 'approved' && $gatewayError->commits === 2,
    'El error recuperable no permitio reintento.'
);

$concurrentToken = str_repeat('C', 64);
$concurrentReturns = new FakeWebpayReturns();
$concurrentReturns->seedProcessing($concurrentToken);
$concurrentGateway = new FakeWebpayReturnGateway($approved);
$concurrent = (new WebpayReturnService(
    $concurrentGateway,
    new FakeReturnPaymentSessions($session),
    $concurrentReturns
))->process(WebpayReturnRequest::fromArray(['token_ws' => $concurrentToken]));
assertWebpayReturn(
    $concurrent->result === 'already_processed'
    && $concurrent->previousResult === 'processing'
    && $concurrentGateway->commits === 0,
    'La concurrencia no bloqueo un segundo commit.'
);

$wooToken = str_repeat('W', 64);
$wooTokenHash = hash('sha256', $wooToken);
$wooBuyOrder = 'VA' . str_repeat('A', 24);
$wooSessionId = 'VA-' . str_repeat('B', 58);
$wooContext = new WebpayReturnContext(
    WebpayReturnContext::SOURCE_WOOCOMMERCE,
    'integration',
    '597055555555',
    $wooBuyOrder,
    $wooSessionId,
    1500,
    time() + 600
);
$wooFinancial = new WebpayCommitResult(
    'AUTHORIZED', 0, 1500, $wooBuyOrder, $wooSessionId, 'AUTH', 'VD', 0,
    '0713', '2026-07-13T12:00:00Z', '6623', 0
);
$wooGateway = new FakeWebpayReturnGateway($wooFinancial);
$wooContexts = new FakeWebpayReturnContexts();
$wooContexts->store($wooTokenHash, $wooContext, 600);
$wooResolver = new FakeWebpayReturnGatewayResolver($wooGateway);
$wooService = new WebpayReturnService(
    new FakeWebpayReturnGateway($approved),
    new FakeReturnPaymentSessions(null),
    new FakeWebpayReturns(),
    $wooContexts,
    $wooResolver
);
$wooResult = $wooService->process(WebpayReturnRequest::fromArray([
    'token_ws' => $wooToken,
]));
assertWebpayReturn(
    $wooResult->result === 'approved'
    && $wooResult->paymentSessionId === null
    && $wooGateway->commits === 1
    && $wooResolver->resolutions === 1,
    'El retorno WooCommerce no uso el commit existente.'
);
assertWebpayReturn(
    $wooContexts->find($wooTokenHash) === null && $wooContexts->forgets === 1,
    'El contexto WooCommerce finalizado no fue eliminado.'
);
$wooRepeated = $wooService->process(WebpayReturnRequest::fromArray([
    'token_ws' => $wooToken,
]));
assertWebpayReturn(
    $wooRepeated->result === 'already_processed'
    && $wooGateway->commits === 1,
    'El retorno WooCommerce repetido duplico commit.'
);
assertWebpayReturn(
    ! str_contains(json_encode($wooResult->toArray()), $wooToken),
    'El resultado WooCommerce expuso el token completo.'
);

$wooRetryToken = str_repeat('Y', 64);
$wooRetryHash = hash('sha256', $wooRetryToken);
$wooRetryContexts = new FakeWebpayReturnContexts();
$wooRetryContexts->store($wooRetryHash, $wooContext, 600);
$wooRetryGateway = new FakeWebpayReturnGateway($wooFinancial);
$wooRetryGateway->exception = new PaymentGatewayException(
    'Falla tecnica controlada.',
    'webpay_connection_error'
);
$wooRetryService = new WebpayReturnService(
    new FakeWebpayReturnGateway($approved),
    new FakeReturnPaymentSessions(null),
    new FakeWebpayReturns(),
    $wooRetryContexts,
    new FakeWebpayReturnGatewayResolver($wooRetryGateway)
);
$wooRetryFailed = $wooRetryService->process(WebpayReturnRequest::fromArray([
    'token_ws' => $wooRetryToken,
]));
assertWebpayReturn(
    $wooRetryFailed->result === 'gateway_error'
    && $wooRetryContexts->find($wooRetryHash) === $wooContext,
    'El error recuperable elimino el contexto WooCommerce.'
);
$wooRetryGateway->exception = null;
$wooRetryRecovered = $wooRetryService->process(WebpayReturnRequest::fromArray([
    'token_ws' => $wooRetryToken,
]));
assertWebpayReturn(
    $wooRetryRecovered->result === 'approved'
    && $wooRetryGateway->commits === 2
    && $wooRetryContexts->find($wooRetryHash) === null,
    'El retorno WooCommerce no se recupero con idempotencia existente.'
);

$wooMismatchToken = str_repeat('Z', 64);
$wooMismatchHash = hash('sha256', $wooMismatchToken);
$wooMismatchContexts = new FakeWebpayReturnContexts();
$wooMismatchContexts->store($wooMismatchHash, $wooContext, 600);
$wooMismatchGateway = new FakeWebpayReturnGateway(new WebpayCommitResult(
    'AUTHORIZED', 0, 1499, $wooBuyOrder, $wooSessionId, 'AUTH', 'VD', 0,
    null, null, null, null
));
$wooMismatch = (new WebpayReturnService(
    new FakeWebpayReturnGateway($approved),
    new FakeReturnPaymentSessions(null),
    new FakeWebpayReturns(),
    $wooMismatchContexts,
    new FakeWebpayReturnGatewayResolver($wooMismatchGateway)
))->process(WebpayReturnRequest::fromArray(['token_ws' => $wooMismatchToken]));
assertWebpayReturn(
    $wooMismatch->result === 'inconsistent'
    && $wooMismatchGateway->commits === 1,
    'La conciliacion WooCommerce acepto un monto inconsistente.'
);

$routes = rest_get_server()->get_routes();
$route = '/veciahorra/v1/payments/webpay/return';
assertWebpayReturn(isset($routes[$route]), 'No se registro la ruta publica Webpay.');
$invalidRest = new WP_REST_Request('POST', $route);
$invalidRest->set_body_params(['token_ws' => $token, 'TBK_TOKEN' => $token]);
$invalidResponse = rest_do_request($invalidRest);
assertWebpayReturn(
    $invalidResponse->get_status() === 400,
    'La ruta no rechazo parametros ambiguos.'
);

echo "PASS webpay-return-foundation-test\n";
