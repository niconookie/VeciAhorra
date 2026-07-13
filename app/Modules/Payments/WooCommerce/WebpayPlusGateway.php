<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\WooCommerce;

use InvalidArgumentException;
use Throwable;
use VeciAhorra\Modules\Payments\Gateway\GatewaySessionResult;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\PaymentSessionContext;
use VeciAhorra\Modules\Payments\Gateway\WebpayGatewayConfiguration;
use VeciAhorra\Modules\Payments\Gateway\WebpayPaymentGateway;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnContext;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnContextRepositoryInterface;
use VeciAhorra\Modules\Payments\Gateway\WebpayTransactionReference;
use VeciAhorra\Modules\Payments\Repository\TransientWebpayReturnContextRepository;
use VeciAhorra\Modules\Payments\Support\WebpayTokenReference;
use VeciAhorra\Modules\Payments\WooCommerce\Contracts\WooCommercePaymentAttemptServiceInterface;

class WebpayPlusGateway extends \WC_Payment_Gateway
{
    public const GATEWAY_ID = 'veciahorra_webpay_plus';
    private const SESSION_PREFIX = 'veciahorra_webpay_flow_';
    private const FLOW_TTL = 600;

    public function __construct()
    {
        $this->id = self::GATEWAY_ID;
        $this->method_title = 'Webpay Plus';
        $this->method_description = 'Paga de forma segura mediante Webpay Plus de Transbank.';
        $this->has_fields = false;
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = (string) $this->get_option('enabled', 'no');
        $this->title = (string) $this->get_option('title', 'Webpay Plus');
        $this->description = (string) $this->get_option(
            'description',
            'Paga con tarjetas de credito o debito a traves de Webpay Plus.'
        );

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options']
        );
        add_action(
            'woocommerce_api_' . self::GATEWAY_ID,
            [$this, 'handleContinuation']
        );
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => 'Habilitar/Deshabilitar',
                'type' => 'checkbox',
                'label' => 'Habilitar Webpay Plus',
                'default' => 'no',
            ],
            'title' => [
                'title' => 'Titulo',
                'type' => 'text',
                'default' => 'Webpay Plus',
                'desc_tip' => true,
                'description' => 'Nombre mostrado durante el checkout.',
            ],
            'description' => [
                'title' => 'Descripcion',
                'type' => 'textarea',
                'default' => 'Paga con tarjetas de credito o debito a traves de Webpay Plus.',
            ],
            'mode' => [
                'title' => 'Modo',
                'type' => 'select',
                'default' => 'integration',
                'options' => [
                    'integration' => 'Integracion (Sandbox)',
                    'production' => 'Produccion',
                ],
            ],
            'commerce_code' => [
                'title' => 'Commerce code',
                'type' => 'text',
                'default' => '',
            ],
            'api_key' => [
                'title' => 'API Key',
                'type' => 'password',
                'default' => '',
                'custom_attributes' => ['autocomplete' => 'new-password'],
            ],
        ];
    }

    public function get_title(): string
    {
        $title = parent::get_title();

        return $this->mode() === 'integration'
            ? rtrim($title) . ' (Sandbox)'
            : $title;
    }

    public function admin_options(): void
    {
        if ($this->enabled === 'yes') {
            try {
                $configuration = $this->configuration();

                if (! WebpayPaymentGateway::supportsEnvironment(
                    $configuration->environment
                )) {
                    throw new InvalidArgumentException(
                        'El modo production permanece bloqueado en este hito.'
                    );
                }
            } catch (Throwable) {
                echo '<div class="notice notice-error inline"><p>'
                    . 'Webpay Plus no esta disponible: revisa el modo, las credenciales '
                    . 'y la URL HTTPS de retorno.'
                    . '</p></div>';
            }
        }

        parent::admin_options();
    }

    public function is_available(): bool
    {
        if ($this->enabled !== 'yes') {
            return false;
        }

        try {
            $configuration = $this->configuration();
        } catch (Throwable) {
            return false;
        }

        if (! WebpayPaymentGateway::supportsEnvironment(
            $configuration->environment
        )) {
            return false;
        }

        $payable = $this->payableContext();

        return $payable !== null
            && $payable['currency'] === 'CLP'
            && $this->normalizeAmount($payable['total']) !== null
            && $payable['needs_payment'];
    }

    public function process_payment($order_id): array
    {
        $orderId = filter_var($order_id, FILTER_VALIDATE_INT);
        $order = $orderId !== false && $orderId > 0
            ? wc_get_order($orderId)
            : false;

        if (! $order instanceof \WC_Order) {
            return $this->paymentFailure('No fue posible iniciar el pago del pedido.');
        }

        try {
            $configuration = $this->configuration();
            $currency = strtoupper(trim((string) $order->get_currency()));
            $amount = $this->normalizeAmount((string) $order->get_total());

            if (
                $currency !== 'CLP'
                || $amount === null
                || (string) $order->get_payment_method() !== self::GATEWAY_ID
                || ! $order->needs_payment()
                || ! WebpayPaymentGateway::supportsEnvironment(
                    $configuration->environment
                )
            ) {
                throw new InvalidArgumentException('Pedido no compatible con Webpay.');
            }

            $orderKey = (string) $order->get_order_key();
            $existing = $this->existingFlow($orderId, $orderKey, $amount, $currency);

            if ($existing !== null) {
                return ['result' => 'success', 'redirect' => $existing];
            }

            $attemptService = $this->paymentAttempts();
            $paymentAttemptId = $attemptService->newAttemptId();
            $idempotencyKey = hash('sha256', implode('|', [
                'woocommerce', (string) $orderId, $orderKey, $amount, $currency,
                $paymentAttemptId,
            ]));
            $context = new PaymentSessionContext(
                'wc-payment-' . hash('sha256', (string) $orderId . '|' . $orderKey),
                'wc-order-' . $orderId,
                $amount,
                $currency,
                gmdate('Y-m-d H:i:s', time() + self::FLOW_TTL),
                $idempotencyKey
            );
            $attempt = $attemptService->create(
                $order,
                $configuration,
                $context,
                $paymentAttemptId
            );
            $result = $this->paymentGateway($configuration)->createSession($context);

            $this->assertGatewayResult($configuration, $result);
            $attemptService->bindToken($attempt, $result->providerSessionId);
            $this->storeReturnContext($configuration, $context, $result);

            return [
                'result' => 'success',
                'redirect' => $this->storeFlow(
                    $orderId,
                    $orderKey,
                    $amount,
                    $currency,
                    $configuration->environment,
                    $result
                ),
            ];
        } catch (Throwable $exception) {
            $this->logFailure($orderId, $exception);

            return $this->paymentFailure(
                'No fue posible conectar con Webpay. Intenta nuevamente.'
            );
        }
    }

    public function handleContinuation(): void
    {
        $orderId = isset($_GET['order_id'])
            ? filter_var(wp_unslash($_GET['order_id']), FILTER_VALIDATE_INT)
            : false;
        $orderKey = isset($_GET['key']) && is_string($_GET['key'])
            ? (string) wp_unslash($_GET['key'])
            : '';
        $flowId = isset($_GET['flow']) && is_string($_GET['flow'])
            ? (string) wp_unslash($_GET['flow'])
            : '';
        $order = $orderId !== false && $orderId > 0
            ? wc_get_order($orderId)
            : false;
        $flow = preg_match('/^[a-f0-9]{32}$/D', $flowId) === 1
            ? $this->sessionGet(self::SESSION_PREFIX . $flowId)
            : null;

        if (
            ! $order instanceof \WC_Order
            || ! is_array($flow)
            || ! hash_equals((string) $order->get_order_key(), $orderKey)
            || ! hash_equals((string) ($flow['order_key'] ?? ''), $orderKey)
            || (int) ($flow['order_id'] ?? 0) !== $orderId
            || (int) ($flow['expires'] ?? 0) < time()
            || ! $order->needs_payment()
            || strtoupper((string) $order->get_currency()) !== ($flow['currency'] ?? '')
            || $this->normalizeAmount((string) $order->get_total()) !== ($flow['amount'] ?? '')
            || ! WebpayPaymentGateway::isAllowedPaymentUrl(
                (string) ($flow['mode'] ?? ''),
                $flow['url'] ?? null
            )
            || ! is_string($flow['token'] ?? null)
            || preg_match('/^[A-Za-z0-9]{16,191}$/D', $flow['token']) !== 1
        ) {
            wp_die('El enlace de pago no es valido o ya expiro.', 'Webpay Plus', ['response' => 400]);
        }

        $this->sessionDelete(self::SESSION_PREFIX . $flowId);
        $this->sessionDelete(self::SESSION_PREFIX . 'order_' . $orderId);
        nocache_headers();
        echo $this->postFormHtml($flow['url'], $flow['token'], $flow['mode']);
        exit;
    }

    protected function paymentGateway(
        WebpayGatewayConfiguration $configuration
    ): PaymentGatewayInterface {
        return new WebpayPaymentGateway($configuration);
    }

    protected function postFormHtml(
        string $url,
        string $token,
        ?string $mode = null
    ): string
    {
        if (! WebpayPaymentGateway::isAllowedPaymentUrl(
            $mode ?? $this->mode(),
            $url
        )) {
            throw new InvalidArgumentException('URL Webpay no permitida.');
        }

        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Webpay Plus</title></head><body>'
            . '<main><p>Seras redirigido de forma segura a Webpay Plus.</p>'
            . '<form id="veciahorra-webpay" method="post" action="'
            . esc_url($url) . '"><input type="hidden" name="token_ws" value="'
            . esc_attr($token) . '"><button type="submit">Continuar a Webpay Plus</button>'
            . '</form></main><script>document.getElementById("veciahorra-webpay").submit();'
            . '</script></body></html>';
    }

    public function validate_mode_field($key, $value): string
    {
        return $this->validatedSetting((string) $key, (string) $value, 'mode');
    }

    public function validate_commerce_code_field($key, $value): string
    {
        return $this->validatedSetting((string) $key, (string) $value, 'commerce_code');
    }

    public function validate_api_key_field($key, $value): string
    {
        return $this->validatedSetting((string) $key, (string) $value, 'api_key');
    }

    private function configuration(): WebpayGatewayConfiguration
    {
        return WebpayGatewaySettings::configuration([
            'mode' => $this->mode(),
            'commerce_code' => (string) $this->get_option('commerce_code', ''),
            'api_key' => (string) $this->get_option('api_key', ''),
        ]);
    }

    private function mode(): string
    {
        return strtolower(trim((string) $this->get_option('mode', 'integration')));
    }

    private function normalizeAmount(string $total): ?string
    {
        $total = trim($total);

        if (preg_match('/^(\d+)(?:\.00)?$/D', $total, $matches) !== 1) {
            return null;
        }

        $integer = ltrim($matches[1], '0');

        return $integer === '' ? null : $integer . '.00';
    }

    private function payableContext(): ?array
    {
        $woocommerce = function_exists('WC') ? WC() : null;

        if (! is_object($woocommerce) || ! isset($woocommerce->cart)) {
            return null;
        }

        return [
            'total' => (string) $woocommerce->cart->get_total('edit'),
            'currency' => strtoupper((string) get_woocommerce_currency()),
            'needs_payment' => (bool) $woocommerce->cart->needs_payment(),
        ];
    }

    private function assertGatewayResult(
        WebpayGatewayConfiguration $configuration,
        GatewaySessionResult $result
    ): void {
        if (
            $result->status !== GatewaySessionResult::STATUS_READY
            || ! is_string($result->redirectUrl)
            || ! WebpayPaymentGateway::isAllowedPaymentUrl(
                $configuration->environment,
                $result->redirectUrl
            )
            || preg_match('/^[A-Za-z0-9]{16,191}$/D', $result->providerSessionId) !== 1
        ) {
            throw new InvalidArgumentException('Respuesta Webpay incompleta.');
        }
    }

    private function storeFlow(
        int $orderId,
        string $orderKey,
        string $amount,
        string $currency,
        string $mode,
        GatewaySessionResult $result
    ): string {
        $flowId = bin2hex(random_bytes(16));
        $this->sessionSet(self::SESSION_PREFIX . $flowId, [
            'order_id' => $orderId,
            'order_key' => $orderKey,
            'amount' => $amount,
            'currency' => $currency,
            'mode' => $mode,
            'url' => $result->redirectUrl,
            'token' => $result->providerSessionId,
            'expires' => time() + self::FLOW_TTL,
        ]);
        $this->sessionSet(self::SESSION_PREFIX . 'order_' . $orderId, $flowId);

        return add_query_arg([
            'order_id' => $orderId,
            'key' => $orderKey,
            'flow' => $flowId,
        ], WC()->api_request_url(self::GATEWAY_ID));
    }

    private function storeReturnContext(
        WebpayGatewayConfiguration $configuration,
        PaymentSessionContext $paymentContext,
        GatewaySessionResult $result
    ): void {
        $this->returnContexts()->store(
            WebpayTokenReference::hash($result->providerSessionId),
            new WebpayReturnContext(
                WebpayReturnContext::SOURCE_WOOCOMMERCE,
                $configuration->environment,
                $configuration->commerceCode,
                WebpayTransactionReference::buyOrder(
                    $paymentContext->checkoutId,
                    $paymentContext->idempotencyKey
                ),
                WebpayTransactionReference::sessionId(
                    $paymentContext->checkoutId
                ),
                $this->integerAmount($paymentContext->amount),
                time() + self::FLOW_TTL
            ),
            self::FLOW_TTL
        );
    }

    protected function returnContexts(): WebpayReturnContextRepositoryInterface
    {
        return new TransientWebpayReturnContextRepository();
    }

    protected function paymentAttempts(): WooCommercePaymentAttemptServiceInterface
    {
        return new WooCommercePaymentAttemptService();
    }

    private function integerAmount(string $amount): int
    {
        if (preg_match('/^(\d+)\.00$/D', $amount, $matches) !== 1) {
            throw new InvalidArgumentException('Monto Webpay no valido.');
        }

        $value = filter_var($matches[1], FILTER_VALIDATE_INT);

        if ($value === false || $value <= 0) {
            throw new InvalidArgumentException('Monto Webpay no valido.');
        }

        return $value;
    }

    private function existingFlow(
        int $orderId,
        string $orderKey,
        string $amount,
        string $currency
    ): ?string {
        $flowId = $this->sessionGet(self::SESSION_PREFIX . 'order_' . $orderId);
        $flow = is_string($flowId)
            ? $this->sessionGet(self::SESSION_PREFIX . $flowId)
            : null;

        if (
            ! is_string($flowId)
            || preg_match('/^[a-f0-9]{32}$/D', $flowId) !== 1
            || ! is_array($flow)
            || (int) ($flow['expires'] ?? 0) < time()
            || ! hash_equals((string) ($flow['order_key'] ?? ''), $orderKey)
            || ($flow['amount'] ?? '') !== $amount
            || ($flow['currency'] ?? '') !== $currency
        ) {
            return null;
        }

        return add_query_arg([
            'order_id' => $orderId,
            'key' => $orderKey,
            'flow' => $flowId,
        ], WC()->api_request_url(self::GATEWAY_ID));
    }

    private function paymentFailure(string $message): array
    {
        if (function_exists('wc_add_notice')) {
            wc_add_notice($message, 'error');
        }

        return ['result' => 'failure'];
    }

    private function logFailure(int $orderId, Throwable $exception): void
    {
        if (! function_exists('wc_get_logger')) {
            return;
        }

        wc_get_logger()->error('No fue posible iniciar la transaccion Webpay.', [
            'source' => self::GATEWAY_ID,
            'order_id' => $orderId,
            'exception' => get_class($exception),
        ]);
    }

    private function sessionGet(string $key): mixed
    {
        return WC()->session->get($key);
    }

    private function sessionSet(string $key, mixed $value): void
    {
        WC()->session->set($key, $value);
    }

    private function sessionDelete(string $key): void
    {
        WC()->session->__unset($key);
    }

    private function validatedSetting(string $key, string $value, string $field): string
    {
        $candidate = trim($value);
        $values = [
            'mode' => $field === 'mode' ? $candidate : $this->mode(),
            'commerce_code' => $field === 'commerce_code'
                ? $candidate
                : (string) $this->get_option('commerce_code', ''),
            'api_key' => $field === 'api_key'
                ? $candidate
                : (string) $this->get_option('api_key', ''),
        ];

        try {
            new WebpayGatewayConfiguration(
                $values['mode'],
                $field === 'commerce_code' ? $values['commerce_code'] : '597055555555',
                $field === 'api_key' ? $values['api_key'] : str_repeat('A', 32),
                'https://example.test/webpay/return'
            );

            return $field === 'mode' ? strtolower($candidate) : $candidate;
        } catch (Throwable) {
            if (class_exists('WC_Admin_Settings')) {
                \WC_Admin_Settings::add_error('La configuracion Webpay ingresada no es valida.');
            }

            return (string) $this->get_option($key, '');
        }
    }
}
