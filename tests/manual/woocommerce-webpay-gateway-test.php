<?php

declare(strict_types=1);

use VeciAhorra\Modules\Payments\Gateway\GatewaySessionResult;
use VeciAhorra\Modules\Payments\Gateway\DummyPaymentGateway;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\PaymentSessionContext;
use VeciAhorra\Modules\Payments\Gateway\WebpayGatewayConfiguration;
use VeciAhorra\Modules\Payments\Gateway\WebpayPaymentGateway;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnContext;
use VeciAhorra\Modules\Payments\Gateway\WebpayTransactionReference;
use VeciAhorra\Modules\Payments\WooCommerce\WebpayGatewayRegistration;
use VeciAhorra\Modules\Payments\WooCommerce\WooCommerceWebpayReturnGatewayResolver;
use VeciAhorra\Modules\Payments\WooCommerce\Contracts\WooCommercePaymentAttemptServiceInterface;
use VeciAhorra\Modules\Payments\WooCommerce\DTO\WooCommercePaymentAttempt;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

function assertWcWebpay(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$GLOBALS['wc_hooks'] = [];
$GLOBALS['wc_filters'] = [];
$GLOBALS['wc_notices'] = [];
$GLOBALS['wc_transients'] = [];
$GLOBALS['wc_order_total'] = '15000.00';
$GLOBALS['wc_order_gateway'] = 'veciahorra_webpay_plus';
$GLOBALS['wc_settings'] = [
    'enabled' => 'yes',
    'title' => 'Webpay Plus',
    'description' => 'Pago seguro.',
    'mode' => ' INTEGRATION ',
    'commerce_code' => '597055555555',
    'api_key' => str_repeat('A', 32),
];

function add_action(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['wc_hooks'][$hook][] = $callback;
}

function add_filter(string $hook, callable $callback): void
{
    $GLOBALS['wc_filters'][$hook][] = $callback;
}

function did_action(string $hook): int
{
    return 1;
}

function rest_url(string $path): string
{
    return 'https://shop.example.test/wp-json/' . $path;
}

function wc_add_notice(string $message, string $type): void
{
    $GLOBALS['wc_notices'][] = [$type, $message];
}

function add_query_arg(array $arguments, string $url): string
{
    return $url . '?' . http_build_query($arguments);
}

function get_woocommerce_currency(): string
{
    return 'CLP';
}

function get_option(string $key, mixed $default = false): mixed
{
    return $key === 'woocommerce_veciahorra_webpay_plus_settings'
        ? $GLOBALS['wc_settings']
        : $default;
}

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function set_transient(string $key, mixed $value, int $expiration): bool
{
    $GLOBALS['wc_transients'][$key] = [
        'value' => $value,
        'expiration' => $expiration,
    ];

    return true;
}

function get_transient(string $key): mixed
{
    return $GLOBALS['wc_transients'][$key]['value'] ?? false;
}

function delete_transient(string $key): bool
{
    unset($GLOBALS['wc_transients'][$key]);

    return true;
}

final class WcWebpaySession
{
    public array $values = [];

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function __unset(string $key): void
    {
        unset($this->values[$key]);
    }
}

final class WcWebpayCart
{
    public function get_total(string $context): string
    {
        return '15000.00';
    }

    public function needs_payment(): bool
    {
        return true;
    }
}

final class WcWebpayRuntime
{
    public WcWebpaySession $session;
    public WcWebpayCart $cart;

    public function __construct()
    {
        $this->session = new WcWebpaySession();
        $this->cart = new WcWebpayCart();
    }

    public function api_request_url(string $gateway): string
    {
        return 'https://shop.example.test/wc-api/' . $gateway;
    }
}

$GLOBALS['wc_runtime'] = new WcWebpayRuntime();

function WC(): WcWebpayRuntime
{
    return $GLOBALS['wc_runtime'];
}

// This class can be loaded and invoked while WooCommerce is absent.
$withoutWooCommerce = new WebpayGatewayRegistration();
$withoutWooCommerce->registerWhenWooCommerceIsReady();
assertWcWebpay(
    ! isset($GLOBALS['wc_filters']['woocommerce_payment_gateways']),
    'El registro no fue seguro sin WooCommerce.'
);

eval(<<<'PHP'
class WC_Payment_Gateway
{
    public string $id = '';
    public string $method_title = '';
    public string $method_description = '';
    public bool $has_fields = false;
    public array $supports = [];
    public array $form_fields = [];
    public array $settings = [];
    public string $enabled = 'no';
    public string $title = '';
    public string $description = '';

    public function init_settings(): void
    {
        $this->settings = $GLOBALS['wc_settings'];
    }

    public function get_option($key, $default = ''): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function get_title(): string
    {
        return $this->title;
    }

    public function process_admin_options(): bool
    {
        return true;
    }
}

class WC_Order
{
    public array $calls = [];
    public array $meta = [];

    public function __construct(private int $id) {}

    public function get_id(): int { return $this->id; }

    public function update_meta_data(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    public function save(): int { return $this->id; }

    public function get_total(): string
    {
        $this->calls[] = 'get_total';
        return $GLOBALS['wc_order_total'];
    }

    public function get_currency(): string
    {
        $this->calls[] = 'get_currency';
        return 'CLP';
    }

    public function get_order_key(): string
    {
        return 'wc_order_backend_key';
    }

    public function get_payment_method(): string
    {
        return $GLOBALS['wc_order_gateway'];
    }

    public function needs_payment(): bool
    {
        return true;
    }
}
PHP);

$GLOBALS['wc_order'] = new WC_Order(41);

function wc_get_order(int $orderId): WC_Order|false
{
    return $orderId === 41 ? $GLOBALS['wc_order'] : false;
}

final class FakeVeciAhorraGateway implements PaymentGatewayInterface
{
    public int $creates = 0;
    public ?PaymentSessionContext $context = null;
    public string $token;
    public ?string $url = 'https://webpay3gint.transbank.cl/webpayserver/initTransaction';

    public function __construct()
    {
        $this->token = str_repeat('T', 32);
    }

    public function createSession(PaymentSessionContext $context): GatewaySessionResult
    {
        $this->creates++;
        $this->context = $context;

        return new GatewaySessionResult(
            'webpay_plus',
            $this->token,
            GatewaySessionResult::STATUS_READY,
            $this->url,
            $context->expiresAt
        );
    }

    public function recoverSession(string $providerSessionId): GatewaySessionResult
    {
        throw new LogicException('No debe recuperar sesiones.');
    }
}

final class FakeWooPaymentAttempts implements WooCommercePaymentAttemptServiceInterface
{
    public int $creates = 0;
    public int $binds = 0;
    public bool $failBind = false;
    private string $attemptId = 'attempt_' . 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    public function newAttemptId(): string { return $this->attemptId; }

    public function create(
        WC_Order $order,
        WebpayGatewayConfiguration $configuration,
        PaymentSessionContext $paymentContext,
        string $paymentAttemptId
    ): WooCommercePaymentAttempt {
        $this->creates++;
        return new WooCommercePaymentAttempt(99, $paymentAttemptId);
    }

    public function bindToken(
        WooCommercePaymentAttempt $attempt,
        string $providerSessionId
    ): void {
        $this->binds++;

        if ($this->failBind) {
            throw new RuntimeException('controlled-bind-failure');
        }
    }
}

class TestableWcWebpayGateway extends \VeciAhorra\Modules\Payments\WooCommerce\WebpayPlusGateway
{
    public FakeWooPaymentAttempts $attempts;

    public function __construct(public FakeVeciAhorraGateway $fake)
    {
        $this->attempts = new FakeWooPaymentAttempts();
        parent::__construct();
    }

    protected function paymentGateway(
        WebpayGatewayConfiguration $configuration
    ): PaymentGatewayInterface {
        return $this->fake;
    }

    protected function paymentAttempts(): WooCommercePaymentAttemptServiceInterface
    {
        return $this->attempts;
    }

    public function form(string $url, string $token): string
    {
        return $this->postFormHtml($url, $token, 'integration');
    }
}

$registration = new WebpayGatewayRegistration();
$registration->registerWhenWooCommerceIsReady();
$registered = ($GLOBALS['wc_filters']['woocommerce_payment_gateways'][0])(['other_gateway']);
assertWcWebpay($registered[0] === 'other_gateway', 'Se alteraron gateways ajenos.');
assertWcWebpay(
    $registered[1] === \VeciAhorra\Modules\Payments\WooCommerce\WebpayPlusGateway::class,
    'El gateway no se registro mediante el filtro WooCommerce.'
);
assertWcWebpay(
    is_subclass_of($registered[1], WC_Payment_Gateway::class),
    'El adaptador no extiende WC_Payment_Gateway.'
);

$fake = new FakeVeciAhorraGateway();
$gateway = new TestableWcWebpayGateway($fake);
assertWcWebpay($gateway->id === 'veciahorra_webpay_plus', 'ID interno inestable.');
assertWcWebpay($gateway->get_title() === 'Webpay Plus (Sandbox)', 'Titulo sandbox incorrecto.');
assertWcWebpay($gateway->description === 'Pago seguro.', 'Descripcion no inicializada.');
assertWcWebpay($gateway->is_available(), 'Gateway valido no disponible.');
$resolvedReturnGateway = (new WooCommerceWebpayReturnGatewayResolver(
    new DummyPaymentGateway()
))->resolve(new WebpayReturnContext(
    WebpayReturnContext::SOURCE_WOOCOMMERCE,
    'integration',
    '597055555555',
    'VA' . str_repeat('A', 24),
    'VA-' . str_repeat('B', 58),
    15000,
    time() + 600
));
assertWcWebpay(
    $resolvedReturnGateway instanceof WebpayPaymentGateway,
    'El retorno WooCommerce no resolvio el gateway Webpay configurado.'
);
assertWcWebpay(
    $gateway->validate_mode_field('mode', ' PRODUCTION ') === 'production',
    'Modo permitido no normalizado.'
);
assertWcWebpay(
    $gateway->validate_mode_field('mode', 'staging') !== 'staging',
    'Modo desconocido aceptado.'
);

$GLOBALS['wc_settings']['enabled'] = 'no';
assertWcWebpay(
    ! (new TestableWcWebpayGateway(new FakeVeciAhorraGateway()))->is_available(),
    'Gateway deshabilitado disponible.'
);
$GLOBALS['wc_settings']['enabled'] = 'yes';
$GLOBALS['wc_settings']['mode'] = 'invalid';
assertWcWebpay(
    ! (new TestableWcWebpayGateway(new FakeVeciAhorraGateway()))->is_available(),
    'Gateway con modo invalido disponible.'
);
$GLOBALS['wc_settings']['mode'] = ' INTEGRATION ';

$result = $gateway->process_payment(41);
assertWcWebpay($result['result'] === 'success', 'process_payment fallo.');
assertWcWebpay($fake->creates === 1, 'No se invoco una vez el gateway VeciAhorra.');
assertWcWebpay(
    $gateway->attempts->creates === 1 && $gateway->attempts->binds === 1,
    'El intento durable no fue creado y vinculado exactamente una vez.'
);
assertWcWebpay($fake->context?->amount === '15000.00', 'No uso el total de WC_Order.');
assertWcWebpay($fake->context?->currency === 'CLP', 'Moneda incorrecta.');
assertWcWebpay(! str_contains($result['redirect'], str_repeat('T', 32)), 'Token expuesto en redirect.');
assertWcWebpay(str_contains($result['redirect'], 'veciahorra_webpay_plus'), 'Continuacion interna invalida.');
$storedContexts = array_values($GLOBALS['wc_transients']);
assertWcWebpay(count($storedContexts) === 1, 'No se guardo el contexto temporal de retorno.');
$storedContext = $storedContexts[0]['value'];
assertWcWebpay(is_array($storedContext), 'Contexto temporal invalido.');
assertWcWebpay(($storedContext['amount'] ?? null) === 15000, 'Monto temporal incorrecto.');
assertWcWebpay(
    ($storedContext['buy_order'] ?? null) === WebpayTransactionReference::buyOrder(
        (string) $fake->context?->checkoutId,
        (string) $fake->context?->idempotencyKey
    ),
    'buy_order temporal incorrecto.'
);
$serializedContexts = serialize($GLOBALS['wc_transients']);
assertWcWebpay(
    ! str_contains($serializedContexts, $fake->token),
    'El contexto temporal almaceno el token completo.'
);
assertWcWebpay(
    ! str_contains($serializedContexts, $GLOBALS['wc_settings']['api_key']),
    'El contexto temporal almaceno la API Key.'
);
foreach (['order_id', 'order_key', 'email', 'phone', 'address'] as $personalField) {
    assertWcWebpay(
        ! array_key_exists($personalField, $storedContext),
        'El contexto temporal almaceno datos personales o del pedido.'
    );
}

$repeated = $gateway->process_payment(41);
assertWcWebpay($fake->creates === 1, 'La repeticion inicio otra transaccion.');
assertWcWebpay($repeated['redirect'] === $result['redirect'], 'No reutilizo la continuacion.');

$form = $gateway->form(
    'https://webpay3gint.transbank.cl/webpayserver/initTransaction',
    str_repeat('T', 32)
);
assertWcWebpay(str_contains($form, 'method="post"'), 'Formulario no usa POST.');
assertWcWebpay(str_contains($form, 'name="token_ws"'), 'Formulario sin token_ws.');
assertWcWebpay(str_contains($form, '<button type="submit">'), 'Formulario sin respaldo manual.');
assertWcWebpay(str_contains($form, '.submit()'), 'Formulario sin envio automatico.');

assertWcWebpay(
    WebpayPaymentGateway::isAllowedPaymentUrl(
        'integration',
        'https://webpay3gint.transbank.cl/webpayserver/initTransaction'
    ),
    'Host sandbox oficial rechazado.'
);
foreach ([
    'http://webpay3gint.transbank.cl/webpayserver/initTransaction',
    'https://webpay3gint.transbank.cl.evil.test/',
    'https://user:pass@webpay3gint.transbank.cl/',
    '/webpayserver/initTransaction',
] as $invalidUrl) {
    assertWcWebpay(
        ! WebpayPaymentGateway::isAllowedPaymentUrl('integration', $invalidUrl),
        'URL Webpay manipulada aceptada.'
    );
}

$missing = $gateway->process_payment(999);
assertWcWebpay($missing['result'] === 'failure', 'Pedido inexistente no controlado.');

$GLOBALS['wc_runtime']->session = new WcWebpaySession();
$GLOBALS['wc_order_total'] = '0.00';
$zero = $gateway->process_payment(41);
assertWcWebpay($zero['result'] === 'failure', 'Monto cero aceptado.');
$GLOBALS['wc_order_total'] = '15000.00';

$GLOBALS['wc_runtime']->session = new WcWebpaySession();
$GLOBALS['wc_order_gateway'] = 'cod';
$wrongGateway = $gateway->process_payment(41);
assertWcWebpay(
    $wrongGateway['result'] === 'failure',
    'Pedido de otro gateway creo un intento Webpay.'
);
$GLOBALS['wc_order_gateway'] = 'veciahorra_webpay_plus';

$GLOBALS['wc_runtime']->session = new WcWebpaySession();
$incompleteFake = new FakeVeciAhorraGateway();
$incompleteFake->url = null;
$incompleteGateway = new TestableWcWebpayGateway($incompleteFake);
$incomplete = $incompleteGateway->process_payment(41);
assertWcWebpay($incomplete['result'] === 'failure', 'Resultado incompleto aceptado.');
assertWcWebpay(
    $incompleteGateway->attempts->creates === 1
    && $incompleteGateway->attempts->binds === 0,
    'Un create fallido fingio vinculacion durable.'
);
assertWcWebpay(
    ! str_contains(json_encode($GLOBALS['wc_notices']), str_repeat('T', 32)),
    'Token completo expuesto en errores.'
);

$GLOBALS['wc_runtime']->session = new WcWebpaySession();
$bindFailureGateway = new TestableWcWebpayGateway(new FakeVeciAhorraGateway());
$bindFailureGateway->attempts->failBind = true;
$bindFailure = $bindFailureGateway->process_payment(41);
assertWcWebpay(
    $bindFailure['result'] === 'failure'
    && $bindFailureGateway->attempts->creates === 1
    && $bindFailureGateway->attempts->binds === 1,
    'El fallo de bind entrego una transaccion sin asociacion durable.'
);

$source = (string) file_get_contents(
    dirname(__DIR__, 2) . '/app/Modules/Payments/WooCommerce/WebpayPlusGateway.php'
);
foreach (['payment_complete(', 'update_status(', 'wc_reduce_stock_levels(', '->commit('] as $forbidden) {
    assertWcWebpay(! str_contains($source, $forbidden), 'Operacion prohibida en adaptador.');
}
assertWcWebpay(! str_contains($source, 'Transbank\\'), 'El adaptador usa el SDK directamente.');
$returnClosureSource = $source
    . (string) file_get_contents(
        dirname(__DIR__, 2) . '/app/Modules/Payments/Service/WebpayReturnService.php'
    )
    . (string) file_get_contents(
        dirname(__DIR__, 2)
        . '/app/Modules/Payments/WooCommerce/WooCommerceWebpayReturnGatewayResolver.php'
    );
foreach ([
    'payment_complete(', 'update_status(', 'wc_reduce_stock_levels(',
    'reduce_order_stock(', 'add_order_note(', 'new Payment(', 'new Delivery(',
    'new Reservation(', 'new Checkout(', 'new Order(',
] as $forbidden) {
    assertWcWebpay(
        ! str_contains($returnClosureSource, $forbidden),
        'El cierre de retorno contiene una escritura de negocio prohibida.'
    );
}

echo "PASS woocommerce-webpay-gateway-test\n";
