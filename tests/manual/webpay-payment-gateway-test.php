<?php

declare(strict_types=1);

use VeciAhorra\Core\Application;
use VeciAhorra\Modules\Payments\Gateway\GatewaySessionResult;
use VeciAhorra\Modules\Payments\Gateway\MockPaymentGateway;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayException;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayConfiguration;
use VeciAhorra\Modules\Payments\Gateway\PaymentConfirmationGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\PaymentSessionContext;
use VeciAhorra\Modules\Payments\Gateway\WebpayGatewayConfiguration;
use VeciAhorra\Modules\Payments\Gateway\WebpayPaymentGateway;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertWebpayGateway(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assertWebpayGatewaySame(mixed $expected, mixed $actual): void
{
    assertWebpayGateway(
        $expected === $actual,
        sprintf(
            "Esperado: %s\nRecibido: %s",
            var_export($expected, true),
            var_export($actual, true)
        )
    );
}

final class FakeWebpayResponse
{
    public function __construct(
        private ?string $token = null,
        private ?string $url = null,
        private mixed $status = null,
        private mixed $responseCode = null,
        private mixed $buyOrder = 'VAAAAAAAAAAAAAAAAAAAAAAAAA',
        private mixed $sessionId = 'VA-BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB',
        private mixed $amount = 15000
    ) {
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getStatus(): mixed
    {
        return $this->status;
    }

    public function getResponseCode(): mixed
    {
        return $this->responseCode;
    }

    public function getBuyOrder(): mixed
    {
        return $this->buyOrder;
    }

    public function getSessionId(): mixed
    {
        return $this->sessionId;
    }

    public function getAmount(): mixed
    {
        return $this->amount;
    }
}

final class FakeWebpayTransaction
{
    public array $createArguments = [];
    public int $commitCalls = 0;
    public bool $throwOnCreate = false;
    public ?Throwable $createException = null;
    public bool $throwOnSecondCommit = false;
    public ?Throwable $statusException = null;
    public ?FakeWebpayResponse $createResponse = null;
    public ?FakeWebpayResponse $commitResponse = null;
    public ?FakeWebpayResponse $statusResponse = null;

    public function create(
        string $buyOrder,
        string $sessionId,
        int|float $amount,
        string $returnUrl
    ): FakeWebpayResponse {
        if ($this->throwOnCreate) {
            throw $this->createException
                ?? new RuntimeException('SDK create failure');
        }

        $this->createArguments = [
            $buyOrder,
            $sessionId,
            $amount,
            $returnUrl,
        ];

        return $this->createResponse ?? throw new RuntimeException(
            'Falta createResponse.'
        );
    }

    public function commit(string $token): FakeWebpayResponse
    {
        $this->commitCalls++;

        if ($this->throwOnSecondCommit && $this->commitCalls > 1) {
            throw new RuntimeException('SDK duplicate commit');
        }

        return $this->commitResponse ?? throw new RuntimeException(
            'Falta commitResponse.'
        );
    }

    public function status(string $token): FakeWebpayResponse
    {
        if ($this->statusException !== null) {
            throw $this->statusException;
        }

        return $this->statusResponse ?? throw new RuntimeException(
            'SDK invalid token.'
        );
    }
}

$token = str_repeat('A', 64);
$paymentUrl = 'https://webpay3gint.transbank.cl/webpayserver/initTransaction';
$configuration = new WebpayGatewayConfiguration(
    ' integration ',
    ' 597055555555 ',
    ' ' . str_repeat('B', 64) . ' ',
    ' https://example.test/webpay/return '
);
$configurationSecret = str_repeat('B', 64);
assertWebpayGatewaySame('integration', $configuration->environment);
assertWebpayGatewaySame('597055555555', $configuration->commerceCode);
assertWebpayGatewaySame($configurationSecret, $configuration->apiKey());
assertWebpayGatewaySame(
    'https://example.test/webpay/return',
    $configuration->returnUrl
);
$transaction = new FakeWebpayTransaction();
$transaction->createResponse = new FakeWebpayResponse($token, $paymentUrl);
$transaction->commitResponse = new FakeWebpayResponse(
    status: 'AUTHORIZED',
    responseCode: 0
);
$transaction->statusResponse = new FakeWebpayResponse(
    status: 'INITIALIZED',
    responseCode: 0
);
$gateway = new WebpayPaymentGateway($configuration, $transaction);
$context = new PaymentSessionContext(
    'ps_abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG',
    'chk_abcdefghijklmnopqrstuvwxyz0123456789ABCDEF',
    '15000.00',
    'CLP',
    '2026-07-11 12:30:00',
    'webpay-idempotency-key-0001'
);

assertWebpayGateway(
    $gateway instanceof PaymentGatewayInterface,
    'Webpay no implementa PaymentGatewayInterface.'
);
$created = $gateway->createSession($context);
assertWebpayGatewaySame('webpay_plus', $created->provider);
assertWebpayGatewaySame(GatewaySessionResult::STATUS_READY, $created->status);
assertWebpayGatewaySame($token, $created->providerSessionId);
assertWebpayGatewaySame($paymentUrl, $created->redirectUrl);
assertWebpayGatewaySame(26, strlen($transaction->createArguments[0]));
assertWebpayGatewaySame(61, strlen($transaction->createArguments[1]));
assertWebpayGatewaySame(15000, $transaction->createArguments[2]);
assertWebpayGatewaySame(
    $configuration->returnUrl,
    $transaction->createArguments[3]
);

$financial = $gateway->commit($token);
assertWebpayGatewaySame('AUTHORIZED', $financial->status);
assertWebpayGatewaySame(15000, $financial->amount);

foreach ([
    ['status' => null],
    ['status' => 'UNKNOWN'],
    ['responseCode' => '0partial'],
    ['responseCode' => 0.0],
    ['responseCode' => true],
    ['responseCode' => []],
    ['amount' => 15000.5],
    ['amount' => 0],
    ['amount' => -1],
    ['amount' => '15000'],
    ['buyOrder' => ''],
    ['buyOrder' => 'OTHER'],
    ['sessionId' => ''],
    ['sessionId' => 'OTHER'],
] as $invalidFinancial) {
    $invalidTransaction = new FakeWebpayTransaction();
    $invalidTransaction->commitResponse = new FakeWebpayResponse(
        status: array_key_exists('status', $invalidFinancial)
            ? $invalidFinancial['status'] : 'AUTHORIZED',
        responseCode: array_key_exists('responseCode', $invalidFinancial)
            ? $invalidFinancial['responseCode'] : 0,
        buyOrder: array_key_exists('buyOrder', $invalidFinancial)
            ? $invalidFinancial['buyOrder'] : 'VAAAAAAAAAAAAAAAAAAAAAAAAA',
        sessionId: array_key_exists('sessionId', $invalidFinancial)
            ? $invalidFinancial['sessionId']
            : 'VA-BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB',
        amount: array_key_exists('amount', $invalidFinancial)
            ? $invalidFinancial['amount'] : 15000
    );

    try {
        (new WebpayPaymentGateway($configuration, $invalidTransaction))
            ->commit($token);
        throw new RuntimeException('Respuesta financiera invalida aceptada.');
    } catch (PaymentGatewayException $exception) {
        assertWebpayGatewaySame(
            'webpay_incomplete_response',
            $exception->errorCode()
        );
    }
}

$sameLogicalRequest = new PaymentSessionContext(
    'ps_different_local_row',
    $context->checkoutId,
    $context->amount,
    $context->currency,
    $context->expiresAt,
    $context->idempotencyKey
);
$secondTransaction = new FakeWebpayTransaction();
$secondTransaction->createResponse = new FakeWebpayResponse($token, $paymentUrl);
(new WebpayPaymentGateway($configuration, $secondTransaction))
    ->createSession($sameLogicalRequest);
assertWebpayGatewaySame(
    $transaction->createArguments[0],
    $secondTransaction->createArguments[0]
);

$status = $gateway->recoverSession($token);
assertWebpayGatewaySame(GatewaySessionResult::STATUS_READY, $status->status);
$transaction->statusResponse = new FakeWebpayResponse(
    status: 'AUTHORIZED',
    responseCode: 0
);
assertWebpayGatewaySame(
    GatewaySessionResult::STATUS_READY,
    $gateway->recoverSession($token)->status
);
$transaction->statusResponse = new FakeWebpayResponse(
    status: 'FAILED',
    responseCode: -1
);
assertWebpayGatewaySame(
    GatewaySessionResult::STATUS_REJECTED,
    $gateway->recoverSession($token)->status
);
$transaction->statusResponse = new FakeWebpayResponse(
    status: 'NULLIFIED',
    responseCode: 0
);
assertWebpayGatewaySame(
    GatewaySessionResult::STATUS_REJECTED,
    $gateway->recoverSession($token)->status
);
$transaction->statusResponse = new FakeWebpayResponse(
    status: 'UNKNOWN',
    responseCode: 0
);

try {
    $gateway->recoverSession($token);
    throw new RuntimeException('Se esperaba rechazo de status desconocido.');
} catch (PaymentGatewayException $exception) {
    assertWebpayGatewaySame(
        'webpay_incomplete_response',
        $exception->errorCode()
    );
}

$transaction->statusResponse = new FakeWebpayResponse(
    status: 'INITIALIZED',
    responseCode: 0
);
assertWebpayGatewaySame(true, $gateway->confirmPayment($token)->isSuccessful());

$transaction->commitResponse = new FakeWebpayResponse(
    status: 'FAILED',
    responseCode: -1
);
assertWebpayGatewaySame(false, $gateway->confirmPayment($token)->isSuccessful());

$transaction->commitResponse = new FakeWebpayResponse(
    status: 'AUTHORIZED',
    responseCode: 0
);
$transaction->statusResponse = new FakeWebpayResponse(
    status: 'AUTHORIZED',
    responseCode: 0
);
$transaction->commitCalls = 0;
$transaction->throwOnSecondCommit = true;
assertWebpayGateway($gateway->confirmPayment($token)->isSuccessful(), 'Fallo commit.');
assertWebpayGateway(
    $gateway->confirmPayment($token)->isSuccessful(),
    'El commit duplicado no se recupero mediante status.'
);

try {
    $gateway->recoverSession('invalid');
    throw new RuntimeException('Se esperaba rechazo de token invalido.');
} catch (PaymentGatewayException $exception) {
    assertWebpayGatewaySame('webpay_invalid_token', $exception->errorCode());
}

$transaction->statusException = new RuntimeException('SDK status failure');

try {
    $gateway->recoverSession($token);
    throw new RuntimeException('Se esperaba error status controlado.');
} catch (PaymentGatewayException $exception) {
    assertWebpayGatewaySame('webpay_status_error', $exception->errorCode());
    assertWebpayGateway(
        $exception->getPrevious() instanceof RuntimeException,
        'El error status no conservo su causa tecnica.'
    );
} finally {
    $transaction->statusException = null;
}

$timeoutTransaction = new FakeWebpayTransaction();
$timeoutTransaction->throwOnCreate = true;
$timeoutTransaction->createException = new ConnectException(
    'Connection timed out with secret-body',
    new Request('POST', 'https://webpay3gint.transbank.cl/')
);
$timeoutGateway = new WebpayPaymentGateway(
    $configuration,
    $timeoutTransaction
);

try {
    $timeoutGateway->createSession($context);
    throw new RuntimeException('Se esperaba timeout controlado.');
} catch (PaymentGatewayException $exception) {
    assertWebpayGatewaySame('webpay_timeout', $exception->errorCode());
    assertWebpayGateway(
        $exception->getPrevious() instanceof ConnectException,
        'El timeout no conservo su causa tecnica.'
    );
    assertWebpayGateway(
        ! str_contains($exception->getMessage(), 'secret-body'),
        'Se filtro el detalle sensible del transporte.'
    );
}

$incompleteTransaction = new FakeWebpayTransaction();
$incompleteTransaction->createResponse = new FakeWebpayResponse(null, null);
$incomplete = new WebpayPaymentGateway(
    $configuration,
    $incompleteTransaction
);

try {
    $incomplete->createSession($context);
    throw new RuntimeException('Se esperaba respuesta incompleta.');
} catch (PaymentGatewayException $exception) {
    assertWebpayGatewaySame('webpay_invalid_token', $exception->errorCode());
}

$failingTransaction = new FakeWebpayTransaction();
$failingTransaction->throwOnCreate = true;
$failing = new WebpayPaymentGateway($configuration, $failingTransaction);

try {
    $failing->createSession($context);
    throw new RuntimeException('Se esperaba error SDK controlado.');
} catch (PaymentGatewayException $exception) {
    assertWebpayGatewaySame('webpay_create_error', $exception->errorCode());
    assertWebpayGateway(
        ! str_contains($exception->getMessage(), 'SDK create failure'),
        'Se filtro el error del SDK.'
    );
}

putenv('payment_gateway=mock');
assertWebpayGateway(
    (new Application())->container()->make(PaymentGatewayInterface::class)
        instanceof MockPaymentGateway,
    'No se selecciono Mock mediante configuracion.'
);
putenv('payment_gateway=unknown');

try {
    new Application();
    throw new RuntimeException('Se esperaba rechazo del gateway desconocido.');
} catch (InvalidArgumentException $exception) {
    assertWebpayGateway(
        ! str_contains($exception->getMessage(), 'unknown'),
        'Se filtro el valor de configuracion desconocido.'
    );
}

putenv('payment_gateway=webpay');
putenv('webpay_environment');
putenv('webpay_commerce_code');
putenv('webpay_api_key');
putenv('webpay_return_url');

try {
    (new Application())->container()->make(PaymentGatewayInterface::class);
    throw new RuntimeException('Se esperaban credenciales obligatorias.');
} catch (InvalidArgumentException) {
}

try {
    new WebpayGatewayConfiguration(
        'invalid',
        '597055555555',
        str_repeat('S', 64),
        'https://example.test/webpay/return'
    );
    throw new RuntimeException('Se esperaba rechazo de ambiente invalido.');
} catch (InvalidArgumentException) {
}

foreach ([
    ['', str_repeat('S', 64), 'codigo de comercio'],
    ['597055555555', '', 'API Key'],
] as [$commerceCode, $apiKey, $field]) {
    try {
        new WebpayGatewayConfiguration(
            'integration',
            $commerceCode,
            $apiKey,
            'https://example.test/webpay/return'
        );
        throw new RuntimeException("Se esperaba rechazo de {$field} vacio.");
    } catch (InvalidArgumentException $exception) {
        assertWebpayGateway(
            ! str_contains($exception->getMessage(), str_repeat('S', 64)),
            'Una excepcion expuso la API Key.'
        );
    }
}

$productionConfiguration = new WebpayGatewayConfiguration(
    ' PrOdUcTiOn ',
    ' 597055555555 ',
    ' ' . str_repeat('S', 64) . ' ',
    ' https://example.test/webpay/return '
);
assertWebpayGatewaySame('production', $productionConfiguration->environment);

try {
    new WebpayPaymentGateway($productionConfiguration);
    throw new RuntimeException('Produccion se activo accidentalmente.');
} catch (InvalidArgumentException $exception) {
    assertWebpayGateway(
        ! str_contains($exception->getMessage(), str_repeat('S', 64)),
        'El rechazo de Produccion expuso la API Key.'
    );
}

putenv('payment_gateway=webpay');
putenv('webpay_environment=integration');
putenv('webpay_commerce_code=597055555555');
putenv('webpay_api_key=' . str_repeat('C', 64));
putenv('webpay_return_url=https://example.test/webpay/return');
assertWebpayGateway(
    (new Application())->container()->make(PaymentGatewayInterface::class)
        instanceof WebpayPaymentGateway,
    'No se selecciono Webpay mediante configuracion.'
);
$webpayApplication = new Application();
assertWebpayGateway(
    $webpayApplication->container()->make(
        PaymentConfirmationGatewayInterface::class
    ) instanceof WebpayPaymentGateway,
    'La confirmacion no usa Webpay mediante configuracion.'
);

foreach ([
    'payment_gateway',
    'webpay_environment',
    'webpay_commerce_code',
    'webpay_api_key',
    'webpay_return_url',
] as $variable) {
    putenv($variable);
}

$woocommerceFallback = static fn (): array => [
    'enabled' => 'yes',
    'mode' => 'integration',
    'commerce_code' => '597055555555',
    'api_key' => str_repeat('W', 64),
];
add_filter(
    'pre_option_woocommerce_veciahorra_webpay_plus_settings',
    $woocommerceFallback
);
$fallbackConfiguration = PaymentGatewayConfiguration::webpay();
assertWebpayGatewaySame(
    PaymentGatewayConfiguration::GATEWAY_WEBPAY,
    PaymentGatewayConfiguration::gateway()
);
assertWebpayGatewaySame('integration', $fallbackConfiguration->environment);
assertWebpayGatewaySame('597055555555', $fallbackConfiguration->commerceCode);
assertWebpayGatewaySame(str_repeat('W', 64), $fallbackConfiguration->apiKey());
remove_filter(
    'pre_option_woocommerce_veciahorra_webpay_plus_settings',
    $woocommerceFallback
);

$appFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    dirname(__DIR__, 2) . '/app',
    FilesystemIterator::SKIP_DOTS
));

foreach ($appFiles as $file) {
    if (
        ! $file->isFile()
        || $file->getExtension() !== 'php'
        || $file->getFilename() === 'WebpayPaymentGateway.php'
    ) {
        continue;
    }

    assertWebpayGateway(
        ! str_contains((string) file_get_contents($file->getPathname()), 'Transbank\\'),
        'Se encontro un import Transbank fuera de WebpayPaymentGateway.'
    );
}

echo "PASS webpay-payment-gateway-test\n";
