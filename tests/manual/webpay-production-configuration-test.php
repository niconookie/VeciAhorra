<?php
declare(strict_types=1);

use Transbank\Webpay\Options;
use VeciAhorra\Modules\Payments\Gateway\{DummyPaymentGateway, PaymentGatewayConfiguration, PaymentGatewayException, PaymentSessionContext, WebpayGatewayConfiguration, WebpayPaymentGateway, WebpayReturnContext};
use VeciAhorra\Modules\Payments\WooCommerce\WooCommerceWebpayReturnGatewayResolver;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$environmentNames = ['VECIAHORRA_PAYMENT_GATEWAY', 'VECIAHORRA_WEBPAY_ENVIRONMENT', 'VECIAHORRA_WEBPAY_PRODUCTION_ENABLED', 'VECIAHORRA_WEBPAY_PRODUCTION_COMMERCE_CODE', 'VECIAHORRA_WEBPAY_PRODUCTION_API_KEY', 'VECIAHORRA_PUBLIC_ORIGIN', 'payment_gateway', 'webpay_environment', 'webpay_commerce_code', 'webpay_api_key', 'webpay_return_url'];
$originalEnvironment = [];
foreach ($environmentNames as $name) {
    $value = getenv($name);
    $originalEnvironment[$name] = is_string($value) ? $value : null;
}
$GLOBALS['production_option_reads'] = 0;
$GLOBALS['production_options'] = [];
$GLOBALS['production_assertions'] = 0;
$GLOBALS['production_groups'] = [];

function get_option(string $name, mixed $default = false): mixed
{
    $GLOBALS['production_option_reads']++;
    return $GLOBALS['production_options'][$name] ?? $default;
}
function current_time(string $type): string
{
    return '2026-08-20 12:00:00';
}
function productionAssert(bool $condition, string $group, string $message): void
{
    $GLOBALS['production_assertions']++;
    $GLOBALS['production_groups'][$group] = true;
    if (! $condition) { throw new RuntimeException("{$group}: {$message}"); }
}
function productionThrows(callable $operation, string $group, string $message): Throwable
{
    try { $operation(); } catch (Throwable $exception) {
        productionAssert(true, $group, $message);
        return $exception;
    }
    throw new RuntimeException("{$group}: {$message}");
}
function productionEnvironment(array $values): void
{
    foreach ($GLOBALS['environmentNames'] as $name) { putenv($name); }
    foreach ($values as $name => $value) { putenv($name . '=' . $value); }
}
function productionValues(string $gate = '1', string $origin = 'https://shop.example.test'): array
{
    return ['VECIAHORRA_PAYMENT_GATEWAY' => 'webpay', 'VECIAHORRA_WEBPAY_ENVIRONMENT' => 'production', 'VECIAHORRA_WEBPAY_PRODUCTION_ENABLED' => $gate, 'VECIAHORRA_WEBPAY_PRODUCTION_COMMERCE_CODE' => '600000000001', 'VECIAHORRA_WEBPAY_PRODUCTION_API_KEY' => str_repeat('P', 48), 'VECIAHORRA_PUBLIC_ORIGIN' => $origin];
}
function productionConfiguration(string $gate = '1', string $origin = 'https://shop.example.test'): WebpayGatewayConfiguration
{
    productionEnvironment(productionValues($gate, $origin));
    return PaymentGatewayConfiguration::webpay();
}

final class ProductionFakeTransaction
{
    public int $creates = 0;
    public int $commits = 0;
    public int $statuses = 0;
    public function create(): never { $this->creates++; throw new RuntimeException('Limite de red alcanzado.'); }
    public function commit(string $token): object { $this->commits++; return $this->response(); }
    public function status(string $token): object { $this->statuses++; return $this->response(); }
    private function response(): object
    {
        return new class {
            public function getStatus(): string { return 'AUTHORIZED'; }
            public function getResponseCode(): int { return 0; }
            public function getAmount(): int { return 10000; }
            public function getBuyOrder(): string { return 'VA' . str_repeat('A', 24); }
            public function getSessionId(): string { return 'VA-' . str_repeat('B', 58); }
            public function getTransactionDate(): string { return '2026-08-20T12:00:00Z'; }
        };
    }
}

try {
    if (($argv[1] ?? '') === '--constant-probe') {
        productionEnvironment([]);
        define('VECIAHORRA_PAYMENT_GATEWAY', 'webpay');
        define('VECIAHORRA_WEBPAY_ENVIRONMENT', 'production');
        define('VECIAHORRA_WEBPAY_PRODUCTION_ENABLED', '1');
        define('VECIAHORRA_WEBPAY_PRODUCTION_COMMERCE_CODE', '600000000001');
        define('VECIAHORRA_WEBPAY_PRODUCTION_API_KEY', str_repeat('C', 48));
        define('VECIAHORRA_PUBLIC_ORIGIN', 'https://constants.example.com');
        try { PaymentGatewayConfiguration::webpay(); } catch (InvalidArgumentException) { exit(0); }
        exit(1);
    }

    productionEnvironment(['payment_gateway' => 'webpay', 'webpay_environment' => 'integration', 'webpay_commerce_code' => '597055555532', 'webpay_api_key' => str_repeat('I', 48), 'webpay_return_url' => 'https://sandbox.example.test/webpay/return']);
    $integration = PaymentGatewayConfiguration::webpay();
    $integrationGateway = new WebpayPaymentGateway($integration);
    $integrationSdk = (new ReflectionMethod($integrationGateway, 'transaction'))->invoke($integrationGateway);
    productionAssert($integration->environment === 'integration' && $integrationSdk->getOptions()->getIntegrationType() === Options::ENVIRONMENT_INTEGRATION, 'authorities', 'Integracion legacy o SDK TEST cambio.');

    $production = productionConfiguration();
    $productionGateway = new WebpayPaymentGateway($production);
    $productionSdk = (new ReflectionMethod($productionGateway, 'transaction'))->invoke($productionGateway);
    productionAssert($production->environment === 'production' && $production->productionCreationEnabled && $productionSdk->getOptions()->getIntegrationType() === Options::ENVIRONMENT_PRODUCTION, 'authorities', 'El bundle getenv no selecciono LIVE.');
    productionAssert(PaymentGatewayConfiguration::gateway() === PaymentGatewayConfiguration::GATEWAY_WEBPAY, 'authorities', 'Produccion cayo a mock.');

    $probe = proc_open([PHP_BINARY, __FILE__, '--constant-probe'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    productionAssert(is_resource($probe), 'authorities', 'No se inicio el probe aislado.');
    if (is_resource($probe)) {
        fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        productionAssert(proc_close($probe) === 0, 'authorities', 'Constantes scoped fueron aceptadas.');
    }

    productionEnvironment(['webpay_environment' => 'production', 'webpay_commerce_code' => '600000000001', 'webpay_api_key' => str_repeat('L', 48)]);
    productionThrows(static fn () => PaymentGatewayConfiguration::webpay(), 'authorities', 'Produccion legacy fue aceptada.');

    productionEnvironment([]);
    $GLOBALS['production_options']['woocommerce_veciahorra_webpay_plus_settings'] = ['enabled' => 'yes', 'mode' => 'production', 'commerce_code' => new class { public function __toString(): string { throw new RuntimeException('Credencial historica leida.'); } }, 'api_key' => new class { public function __toString(): string { throw new RuntimeException('Credencial historica leida.'); } }];
    $historical = productionThrows(static fn () => PaymentGatewayConfiguration::webpay(), 'authorities', 'WooCommerce production fue aceptado.');
    productionAssert($historical instanceof InvalidArgumentException && str_contains($historical->getMessage(), 'entorno de despliegue'), 'authorities', 'El rechazo historico no fue seguro.');
    $GLOBALS['production_options'] = [];

    foreach (['VECIAHORRA_PAYMENT_GATEWAY', 'VECIAHORRA_WEBPAY_PRODUCTION_COMMERCE_CODE', 'VECIAHORRA_WEBPAY_PRODUCTION_API_KEY', 'VECIAHORRA_PUBLIC_ORIGIN'] as $missing) {
        $values = productionValues(); unset($values[$missing]); productionEnvironment($values);
        try {
            $selectedGateway = PaymentGatewayConfiguration::gateway();
            productionAssert($selectedGateway === PaymentGatewayConfiguration::GATEWAY_WEBPAY, 'authorities', 'Bundle incompleto cayo a mock.');
        } catch (InvalidArgumentException) {
            productionAssert($missing === 'VECIAHORRA_PAYMENT_GATEWAY', 'authorities', 'Seleccion productiva fallo por requisito no relacionado.');
        }
        $failure = productionThrows(static fn () => PaymentGatewayConfiguration::webpay(), 'authorities', 'Bundle incompleto fue aceptado.');
        productionAssert($failure instanceof InvalidArgumentException, 'authorities', 'Produccion incompleta cayo a otro gateway.');
    }

    foreach (['', '0', 'true', 'TRUE', '01', '1 '] as $gate) {
        $configuration = productionConfiguration($gate); $fake = new ProductionFakeTransaction();
        $failure = productionThrows(fn () => (new WebpayPaymentGateway($configuration, $fake))->createSession(new PaymentSessionContext('payment-session', 'checkout', '10000.00', 'CLP', '2026-08-20 13:00:00', 'idempotency')), 'gate_return', 'Gate ambiguo permitio create.');
        productionAssert($failure instanceof PaymentGatewayException && $failure->errorCode() === 'webpay_production_disabled' && $fake->creates === 0, 'gate_return', 'Gate cerrado alcanzo red.');
    }

    $closed = productionConfiguration('0'); $returnFake = new ProductionFakeTransaction(); $closedGateway = new WebpayPaymentGateway($closed, $returnFake);
    $closedGateway->commit(str_repeat('T', 32)); $closedGateway->recoverSession(str_repeat('T', 32));
    productionAssert($returnFake->commits === 1 && $returnFake->statuses === 1, 'gate_return', 'Gate cerrado bloqueo commit o status.');
    $readsBeforeResolver = $GLOBALS['production_option_reads'];
    $resolved = (new WooCommerceWebpayReturnGatewayResolver(new DummyPaymentGateway()))->resolve(new WebpayReturnContext(WebpayReturnContext::SOURCE_WOOCOMMERCE, 'production', '600000000001', 'VA' . str_repeat('A', 24), 'VA-' . str_repeat('B', 58), 10000, time() + 600));
    productionAssert($resolved instanceof WebpayPaymentGateway && $GLOBALS['production_option_reads'] === $readsBeforeResolver, 'gate_return', 'Resolver productivo consulto opciones.');

    $invalidOrigins = ['http://example.com', 'https://localhost', 'https://foo.localhost', 'https://example.local', 'https://127.0.0.1', 'https://127.1', 'https://2130706433', 'https://017700000001', 'https://0x7f000001', 'https://[::1]', 'https://[fc00::1]', 'https://[fe80::1]', 'https://10.0.0.1', 'https://172.16.0.1', 'https://192.168.1.1', 'https://user:pass@example.com', 'https://example.com:443', 'https://example.com/path', 'https://example.com?x=1', 'https://example.com#x', 'https://example.com.', 'https://-example.com', 'https://example-.com', 'https://example..com', 'https://singlelabel', "https://examp\u{00E9}.com", "https://example.com\\evil", "https://example.com\n.evil"];
    foreach ($invalidOrigins as $case => $origin) {
        productionEnvironment(productionValues('1', $origin));
        productionThrows(static fn () => PaymentGatewayConfiguration::webpay(), 'origins', "Origen invalido aceptado: {$case}.");
    }
    foreach (['https://example.com' => 'https://example.com/wp-json/veciahorra/v1/payments/webpay/return', 'https://payments.example.com' => 'https://payments.example.com/wp-json/veciahorra/v1/payments/webpay/return', 'https://EXAMPLE.COM/' => 'https://example.com/wp-json/veciahorra/v1/payments/webpay/return'] as $origin => $expected) {
        productionAssert(productionConfiguration('1', $origin)->returnUrl === $expected, 'origins', 'Origen valido no produjo URL canonica.');
    }
    productionAssert(WebpayPaymentGateway::isAllowedPaymentUrl('production', 'https://webpay3g.transbank.cl/webpayserver/initTransaction') && ! WebpayPaymentGateway::isAllowedPaymentUrl('production', 'https://webpay3gint.transbank.cl/webpayserver/initTransaction'), 'sdk_allowlist', 'Allowlist LIVE incorrecta.');

    foreach (['authorities', 'gate_return', 'origins', 'sdk_allowlist'] as $requiredGroup) {
        productionAssert(isset($GLOBALS['production_groups'][$requiredGroup]), 'coverage', "Falto grupo {$requiredGroup}.");
    }
    productionAssert($GLOBALS['production_assertions'] >= 60, 'coverage', 'Cobertura minima no alcanzada.');
    echo 'WEBPAY_PRODUCTION_CONFIGURATION=PASS assertions=' . $GLOBALS['production_assertions'] . "\n";
} finally {
    foreach ($originalEnvironment as $name => $value) { $value === null ? putenv($name) : putenv($name . '=' . $value); }
}
