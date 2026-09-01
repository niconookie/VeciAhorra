<?php

declare(strict_types=1);

use VeciAhorra\Core\Application;
use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Payments\Contracts\PaymentTerminalOutcomeInterface;
use VeciAhorra\Modules\Payments\Controller\PaymentController;
use VeciAhorra\Modules\Payments\Controller\WebpayReturnController;
use VeciAhorra\Modules\Payments\Gateway\WebpayCommitResult;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnGatewayInterface;
use VeciAhorra\Modules\Payments\Models\PaymentSession;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Support\WordPressSiteScope;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;
use VeciAhorra\Modules\Payments\Repository\WebpayReturnRepository;
use VeciAhorra\Modules\Payments\Requests\WebpayReturnRequest;
use VeciAhorra\Modules\Payments\Routes\PaymentRoutes;
use VeciAhorra\Modules\Payments\Service\WebpayReturnService;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertWebpayTimeoutIntegration(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

final class TimeoutGateway implements WebpayReturnGatewayInterface
{
    public int $commits = 0;

    public function commit(string $token): WebpayCommitResult
    {
        ++$this->commits;
        throw new RuntimeException('Un timeout no debe ejecutar commit.');
    }
}

final class TimeoutSessions extends PaymentSessionRepository
{
    public function __construct(private ?array $session)
    {
    }

    public function findByProviderSessionId(string $providerSessionId): ?array
    {
        return $this->session;
    }

    public function findByPublicId(string $publicId): ?array
    {
        return $this->session;
    }
}

final class TimeoutTerminalOutcome implements PaymentTerminalOutcomeInterface
{
    public int $cancellations = 0;

    public function cancel(int $paymentSessionId, string $checkoutPublicId): void
    {
        ++$this->cancellations;
    }
}

function timeoutOrigin(
    string $seed,
    string $token,
    string $buyOrder,
    string $sessionId,
    string $paymentAttemptId,
    string $checkoutId,
    string $environment = 'integration',
    ?string $siteScope = null,
    ?string $createdAt = null,
    ?string $expiresAt = null
): DurablePaymentOrigin {
    $createdAt ??= gmdate('Y-m-d H:i:s', time() - 60);
    $expiresAt ??= gmdate('Y-m-d H:i:s', time() + 3600);

    return new DurablePaymentOrigin(
        'poc_' . substr(hash('sha256', 'origin-' . $seed), 0, 40),
        $siteScope ?? WordPressSiteScope::current(),
        DurablePaymentOrigin::ORIGIN_VECIAHORRA,
        $checkoutId,
        'webpay_plus',
        $paymentAttemptId,
        1500,
        $environment,
        hash('sha256', 'merchant-' . $seed),
        $buyOrder,
        $sessionId,
        hash('sha256', $token),
        1,
        $createdAt,
        $createdAt,
        $expiresAt
    );
}

function timeoutSession(
    DurablePaymentOrigin $origin,
    string $token,
    string $status = PaymentSession::STATUS_READY,
    ?int $paymentId = null,
    ?string $confirmedAt = null,
    ?string $redirectUrl = null
): array {
    return [
        'id' => 91001,
        'public_id' => $origin->paymentAttemptId(),
        'checkout_public_id' => $origin->originResourceId(),
        'amount' => '1500.00',
        'provider' => 'webpay_plus',
        'provider_session_id' => $token,
        'status' => $status,
        'payment_id' => $paymentId,
        'confirmed_at' => $confirmedAt,
        'expires_at' => $origin->expiresAt(),
        'redirect_url' => $redirectUrl
            ?? 'https://webpay3gint.transbank.cl/webpayserver/initTransaction',
    ];
}

global $wpdb;
$originTable = $wpdb->prefix . Config::TABLE_PREFIX . 'payment_origin_contexts';
$returnTable = $wpdb->prefix . Config::TABLE_PREFIX . 'webpay_returns';
$paymentTable = $wpdb->prefix . Config::TABLE_PREFIX . 'payments';
$origins = new PaymentOriginContextRepository();
$returns = new WebpayReturnRepository();
$gateway = new TimeoutGateway();
$terminal = new TimeoutTerminalOutcome();
$materializer = (new Application())->container()->make(
    \VeciAhorra\Modules\Payments\Reconciliation\Service\WebpayReconciliationMaterializer::class
);
$createdOriginIds = [];
$createdTokenHashes = [];
$paymentsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$paymentTable}");

try {
    $seed = bin2hex(random_bytes(8));
    $token = str_repeat('A', 48) . substr($seed, 0, 16);
    $buyOrder = 'VA' . strtoupper(substr(hash('sha256', 'buy-' . $seed), 0, 24));
    $financialSessionId = 'VA-' . strtoupper(substr(hash('sha256', 'session-' . $seed), 0, 58));
    $paymentAttemptId = 'ps_' . substr(hash('sha256', 'attempt-' . $seed), 0, 43);
    $checkoutId = 'chk_' . substr(hash('sha256', 'checkout-' . $seed), 0, 43);
    $origin = timeoutOrigin(
        $seed,
        $token,
        $buyOrder,
        $financialSessionId,
        $paymentAttemptId,
        $checkoutId
    );
    $createdOriginIds[] = $origins->create($origin);
    $createdTokenHashes[] = $origin->tokenHash();
    $service = new WebpayReturnService(
        $gateway,
        new TimeoutSessions(timeoutSession($origin, $token)),
        $returns,
        $materializer,
        null,
        null,
        $origins,
        $terminal
    );
    $controller = new WebpayReturnController($service);
    add_filter(
        'veciahorra_frontend_checkout_url',
        static fn (): string => 'https://veciahorra.cl/checkout/'
    );
    $paymentController = (new ReflectionClass(PaymentController::class))
        ->newInstanceWithoutConstructor();
    $route = new PaymentRoutes($paymentController, $controller);
    $request = new WP_REST_Request(
        'GET',
        '/veciahorra/v1/payments/webpay/return'
    );
    $request->set_query_params([
        'TBK_ORDEN_COMPRA' => $buyOrder,
        'TBK_ID_SESION' => $financialSessionId,
    ]);
    $response = $route->webpayReturn($request);
    $data = $response->get_data();
    assertWebpayTimeoutIntegration(
        $response->get_status() === 303
            && ($data['data']['result'] ?? null) === 'timed_out'
            && str_starts_with(
                (string) $response->get_headers()['Location'],
                'https://veciahorra.cl/checkout/'
            )
            && ! str_contains(
                (string) $response->get_headers()['Location'],
                $buyOrder
            )
            && ! str_contains(
                (string) $response->get_headers()['Location'],
                $financialSessionId
            ),
        'El timeout no produjo una redireccion publica segura.'
    );
    assertWebpayTimeoutIntegration(
        $gateway->commits === 0 && $terminal->cancellations === 1,
        'El timeout ejecuto commit o no aplico la transicion terminal.'
    );
    $stored = $returns->find((string) $origin->tokenHash());
    assertWebpayTimeoutIntegration(
        ($stored['processing_status'] ?? null) === 'completed'
            && ($stored['result_status'] ?? null) === 'timed_out'
            && ($stored['financial_status'] ?? null) === null
            && ($stored['financial_fingerprint'] ?? null) === null,
        'El timeout no dejo evidencia durable no financiera.'
    );
    $repeated = $service->process(WebpayReturnRequest::fromArray([
        'TBK_ORDEN_COMPRA' => $buyOrder,
        'TBK_ID_SESION' => $financialSessionId,
    ], 'GET'));
    assertWebpayTimeoutIntegration(
        $repeated->result === 'already_processed'
            && $repeated->previousResult === 'timed_out'
            && $gateway->commits === 0
            && $terminal->cancellations === 2,
        'El timeout repetido no fue idempotente.'
    );

    foreach ([
        ['VA' . str_repeat('F', 24), $financialSessionId],
        [$buyOrder, 'VA-' . str_repeat('E', 58)],
    ] as [$unknownBuyOrder, $unknownSessionId]) {
        try {
            $service->process(WebpayReturnRequest::fromArray([
                'TBK_ORDEN_COMPRA' => $unknownBuyOrder,
                'TBK_ID_SESION' => $unknownSessionId,
            ], 'GET'));
            throw new RuntimeException('Se aceptaron referencias desconocidas.');
        } catch (InvalidArgumentException) {
        }
    }

    $secondSeed = $seed . 'b';
    $secondToken = str_repeat('B', 64);
    $secondBuyOrder = 'VA' . strtoupper(substr(hash('sha256', 'buy-' . $secondSeed), 0, 24));
    $secondSessionId = 'VA-' . strtoupper(substr(hash('sha256', 'session-' . $secondSeed), 0, 58));
    $secondOrigin = timeoutOrigin(
        $secondSeed,
        $secondToken,
        $secondBuyOrder,
        $secondSessionId,
        'ps_' . substr(hash('sha256', 'attempt-' . $secondSeed), 0, 43),
        'chk_' . substr(hash('sha256', 'checkout-' . $secondSeed), 0, 43)
    );
    $createdOriginIds[] = $origins->create($secondOrigin);
    $createdTokenHashes[] = $secondOrigin->tokenHash();
    try {
        $service->process(WebpayReturnRequest::fromArray([
            'TBK_ORDEN_COMPRA' => $buyOrder,
            'TBK_ID_SESION' => $secondSessionId,
        ], 'GET'));
        throw new RuntimeException('Se mezclaron contextos durables distintos.');
    } catch (InvalidArgumentException) {
    }

    $invalidAuthorities = [];
    $siteOrigin = timeoutOrigin(
        $seed . 'site', str_repeat('C', 64),
        'VA' . str_repeat('C', 24), 'VA-' . str_repeat('C', 58),
        'ps_' . str_repeat('C', 43), 'chk_' . str_repeat('C', 43),
        'integration', 'wp-blog:999999'
    );
    $invalidAuthorities[] = ['site_scope', $siteOrigin, timeoutSession($siteOrigin, str_repeat('C', 64))];
    $environmentOrigin = timeoutOrigin(
        $seed . 'environment', str_repeat('D', 64),
        'VA' . str_repeat('D', 24), 'VA-' . str_repeat('D', 58),
        'ps_' . str_repeat('D', 43), 'chk_' . str_repeat('D', 43), 'production'
    );
    $invalidAuthorities[] = [
        'environment',
        $environmentOrigin,
        timeoutSession($environmentOrigin, str_repeat('D', 64))
    ];
    $confirmedOrigin = timeoutOrigin(
        $seed . 'confirmed', str_repeat('E', 64),
        'VA' . str_repeat('E', 24), 'VA-' . str_repeat('E', 58),
        'ps_' . str_repeat('E', 43), 'chk_' . str_repeat('E', 43)
    );
    $invalidAuthorities[] = [
        'confirmed',
        $confirmedOrigin,
        timeoutSession(
            $confirmedOrigin,
            str_repeat('E', 64),
            PaymentSession::STATUS_CONFIRMED,
            77,
            gmdate('Y-m-d H:i:s')
        ),
    ];
    $expiredOrigin = timeoutOrigin(
        $seed . 'expired', str_repeat('F', 64),
        'VA' . str_repeat('F', 24), 'VA-' . str_repeat('F', 58),
        'ps_' . str_repeat('F', 43), 'chk_' . str_repeat('F', 43),
        'integration', null,
        gmdate(
            'Y-m-d H:i:s',
            strtotime(current_time('mysql')) - 7200
        ),
        gmdate(
            'Y-m-d H:i:s',
            strtotime(current_time('mysql')) - 3600
        )
    );
    $invalidAuthorities[] = [
        'expired',
        $expiredOrigin,
        timeoutSession($expiredOrigin, str_repeat('F', 64))
    ];

    foreach ($invalidAuthorities as [$case, $invalidOrigin, $invalidSession]) {
        $createdOriginIds[] = $origins->create($invalidOrigin);
        $createdTokenHashes[] = $invalidOrigin->tokenHash();
        $invalidService = new WebpayReturnService(
            $gateway,
            new TimeoutSessions($invalidSession),
            $returns,
            $materializer,
            null,
            null,
            $origins,
            $terminal
        );
        try {
            $invalidService->process(WebpayReturnRequest::fromArray([
                'TBK_ORDEN_COMPRA' => $invalidOrigin->buyOrder(),
                'TBK_ID_SESION' => $invalidOrigin->financialSessionId(),
            ], 'GET'));
            throw new RuntimeException(
                "Se acepto una autoridad durable invalida: {$case}."
            );
        } catch (InvalidArgumentException) {
        }
    }

    assertWebpayTimeoutIntegration(
        $gateway->commits === 0
            && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$paymentTable}")
                === $paymentsBefore,
        'Un retorno sin token creo un pago o ejecuto commit.'
    );
} finally {
    foreach (array_filter($createdTokenHashes, 'is_string') as $tokenHash) {
        $wpdb->delete($returnTable, ['token_hash' => $tokenHash]);
    }
    foreach ($createdOriginIds as $originId) {
        $wpdb->delete($originTable, ['id' => $originId]);
    }
}

echo "PASS webpay-timeout-return-integration-test assertions=14\n";
