<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

use InvalidArgumentException;
use Throwable;
use GuzzleHttp\Exception\ConnectException;
use Transbank\Webpay\Options;
use Transbank\Webpay\WebpayPlus\Transaction;
use VeciAhorra\Modules\Payments\Models\PaymentConfirmationResult;

final class WebpayPaymentGateway implements
    PaymentGatewayInterface,
    PaymentConfirmationGatewayInterface,
    WebpayReturnGatewayInterface
{
    private const PROVIDER = 'webpay_plus';
    private const PAYMENT_HOSTS = [
        'integration' => 'webpay3gint.transbank.cl',
        'production' => 'webpay3g.transbank.cl',
    ];

    private object $transaction;

    public function __construct(
        private WebpayGatewayConfiguration $configuration,
        ?object $transaction = null
    ) {
        if ($configuration->environment !== 'integration') {
            throw new InvalidArgumentException(
                'Webpay solo admite el ambiente integration en este hito.'
            );
        }

        $this->transaction = $transaction ?? new Transaction(new Options(
            $configuration->apiKey(),
            $configuration->commerceCode,
            Options::ENVIRONMENT_INTEGRATION,
            30
        ));
    }

    public function createSession(
        PaymentSessionContext $context
    ): GatewaySessionResult {
        $amount = $this->amount($context->amount);
        $buyOrder = $context->buyOrder ?? WebpayTransactionReference::buyOrder(
            $context->checkoutId, $context->idempotencyKey
        );
        $sessionId = $context->financialSessionId
            ?? WebpayTransactionReference::sessionId($context->checkoutId);

        try {
            $response = $this->transaction->create(
                $buyOrder,
                $sessionId,
                $amount,
                $this->configuration->returnUrl
            );
            $token = $response->getToken();
            $url = $response->getUrl();
        } catch (Throwable $exception) {
            throw $this->sdkFailure('create', $exception);
        }

        $this->assertToken($token);
        $this->assertPaymentUrl($url);

        return new GatewaySessionResult(
            self::PROVIDER,
            $token,
            GatewaySessionResult::STATUS_READY,
            $url,
            $context->expiresAt
        );
    }

    public static function supportsEnvironment(string $environment): bool
    {
        return strtolower(trim($environment)) === 'integration';
    }

    public static function isAllowedPaymentUrl(
        string $environment,
        mixed $url
    ): bool {
        $environment = strtolower(trim($environment));

        if (! is_string($url) || ! isset(self::PAYMENT_HOSTS[$environment])) {
            return false;
        }

        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? ''))
                === self::PAYMENT_HOSTS[$environment]
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443);
    }

    public function recoverSession(
        string $providerSessionId
    ): GatewaySessionResult {
        $this->assertToken($providerSessionId);

        try {
            $response = $this->transaction->status($providerSessionId);

            return $this->statusResult($providerSessionId, $response);
        } catch (PaymentGatewayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->sdkFailure('status', $exception);
        }
    }

    public function confirmPayment(
        string $providerReference
    ): PaymentConfirmationResult {
        try {
            $result = $this->commit($providerReference);

            return $result->isApproved()
                ? PaymentConfirmationResult::paid()
                : PaymentConfirmationResult::failed();
        } catch (PaymentGatewayException $exception) {
            try {
                $status = $this->transaction->status($providerReference);

                return $this->confirmationResult($status);
            } catch (Throwable) {
                throw $exception;
            }
        }
    }

    public function commit(string $token): WebpayCommitResult
    {
        $this->assertToken($token);

        try {
            $response = $this->transaction->commit($token);
        } catch (Throwable $exception) {
            throw $this->sdkFailure('commit', $exception);
        }

        return $this->commitResult($response);
    }

    private function statusResult(
        string $token,
        object $response
    ): GatewaySessionResult {
        $this->assertTransactionDetails($response);
        $status = $this->responseStatus($response);
        $gatewayStatus = match ($status) {
            'INITIALIZED', 'AUTHORIZED', 'CAPTURED' =>
                GatewaySessionResult::STATUS_READY,
            'FAILED', 'REVERSED', 'NULLIFIED', 'PARTIALLY_NULLIFIED' =>
                GatewaySessionResult::STATUS_REJECTED,
            default => throw $this->failure(
                'Webpay devolvio un estado desconocido.',
                'webpay_incomplete_response'
            ),
        };

        return new GatewaySessionResult(
            self::PROVIDER,
            $token,
            $gatewayStatus,
            null,
            current_time('mysql'),
            $gatewayStatus === GatewaySessionResult::STATUS_REJECTED
                ? 'webpay_' . strtolower($status)
                : null
        );
    }

    private function confirmationResult(
        object $response
    ): PaymentConfirmationResult {
        $this->assertTransactionDetails($response);
        $status = $this->responseStatus($response);
        $responseCode = $response->getResponseCode();

        if (! is_int($responseCode)) {
            throw $this->failure(
                'Webpay no devolvio response_code.',
                'webpay_incomplete_response'
            );
        }

        return in_array($status, ['AUTHORIZED', 'CAPTURED'], true)
            && $responseCode === 0
                ? PaymentConfirmationResult::paid()
                : PaymentConfirmationResult::failed();
    }

    private function responseStatus(object $response): string
    {
        $status = $response->getStatus();

        if (! is_string($status) || trim($status) === '') {
            throw $this->failure(
                'Webpay no devolvio status.',
                'webpay_incomplete_response'
            );
        }

        return strtoupper(trim($status));
    }

    private function assertTransactionDetails(object $response): void
    {
        $buyOrder = $response->getBuyOrder();
        $sessionId = $response->getSessionId();
        $amount = $response->getAmount();

        if (
            ! is_string($buyOrder)
            || trim($buyOrder) === ''
            || ! is_string($sessionId)
            || trim($sessionId) === ''
            || (! is_int($amount) && ! is_float($amount))
            || ! is_finite((float) $amount)
            || $amount <= 0
        ) {
            throw $this->failure(
                'Webpay devolvio datos de transaccion incompletos.',
                'webpay_incomplete_response'
            );
        }
    }

    private function amount(string $amount): int
    {
        if (preg_match('/^(\d+)\.00$/D', $amount, $matches) !== 1) {
            throw $this->failure(
                'Webpay Integration requiere un monto CLP entero.',
                'webpay_invalid_amount'
            );
        }

        $value = filter_var($matches[1], FILTER_VALIDATE_INT);

        if ($value === false || $value <= 0) {
            throw $this->failure(
                'El monto Webpay no es valido.',
                'webpay_invalid_amount'
            );
        }

        return $value;
    }

    private function commitResult(object $response): WebpayCommitResult
    {
        $status = $response->getStatus();
        $responseCode = $response->getResponseCode();
        $amount = $response->getAmount();
        $buyOrder = $response->getBuyOrder();
        $sessionId = $response->getSessionId();
        $recognized = [
            'AUTHORIZED', 'FAILED', 'REVERSED', 'NULLIFIED',
            'PARTIALLY_NULLIFIED', 'CAPTURED',
        ];

        if (
            ! is_string($status)
            || ! in_array(strtoupper(trim($status)), $recognized, true)
            || ! is_int($responseCode)
            || (! is_int($amount) && ! is_float($amount))
            || ! is_finite((float) $amount)
            || $amount <= 0
            || floor((float) $amount) !== (float) $amount
            || ! is_string($buyOrder)
            || preg_match('/^VA[A-F0-9]{24}$/D', $buyOrder) !== 1
            || ! is_string($sessionId)
            || preg_match('/^VA-[A-F0-9]{58}$/D', $sessionId) !== 1
        ) {
            throw $this->failure(
                'Webpay devolvio una respuesta financiera invalida.',
                'webpay_incomplete_response'
            );
        }

        $cardDetail = method_exists($response, 'getCardDetail')
            ? $response->getCardDetail()
            : null;
        $cardLastFour = is_array($cardDetail)
            && isset($cardDetail['card_number'])
            && is_string($cardDetail['card_number'])
            && preg_match('/^\d{4}$/D', $cardDetail['card_number']) === 1
                ? $cardDetail['card_number']
                : null;

        return new WebpayCommitResult(
            strtoupper(trim($status)),
            $responseCode,
            (int) $amount,
            $buyOrder,
            $sessionId,
            $this->optionalString($response, 'getAuthorizationCode'),
            $this->optionalString($response, 'getPaymentTypeCode'),
            $this->optionalInt($response, 'getInstallmentsNumber'),
            $this->optionalString($response, 'getAccountingDate'),
            $this->optionalString($response, 'getTransactionDate'),
            $cardLastFour,
            method_exists($response, 'getBalance')
                && (is_int($response->getBalance())
                    || is_float($response->getBalance()))
                    ? $response->getBalance()
                    : null
        );
    }

    private function optionalString(object $response, string $method): ?string
    {
        if (! method_exists($response, $method)) {
            return null;
        }

        $value = $response->{$method}();

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function optionalInt(object $response, string $method): ?int
    {
        if (! method_exists($response, $method)) {
            return null;
        }

        $value = $response->{$method}();

        return is_int($value) ? $value : null;
    }

    private function assertToken(mixed $token): void
    {
        if (
            ! is_string($token)
            || preg_match('/^[A-Za-z0-9]{16,191}$/D', $token) !== 1
        ) {
            throw $this->failure(
                'Webpay devolvio un token invalido.',
                'webpay_invalid_token'
            );
        }
    }

    private function assertPaymentUrl(mixed $url): void
    {
        if (! self::isAllowedPaymentUrl($this->configuration->environment, $url)) {
            throw $this->failure(
                'Webpay devolvio una URL de pago invalida.',
                'webpay_invalid_url'
            );
        }
    }

    private function failure(
        string $message,
        string $code,
        ?Throwable $previous = null
    ): PaymentGatewayException {
        return new PaymentGatewayException(
            $message,
            $code,
            $previous
        );
    }

    private function sdkFailure(
        string $operation,
        Throwable $exception
    ): PaymentGatewayException {
        if ($exception instanceof ConnectException) {
            $timeout = str_contains(
                strtolower($exception->getMessage()),
                'timed out'
            ) || str_contains(
                strtolower($exception->getMessage()),
                'timeout'
            );

            return $this->failure(
                $timeout
                    ? 'La operacion Webpay excedio el tiempo de espera.'
                    : 'No fue posible conectar con Webpay.',
                $timeout ? 'webpay_timeout' : 'webpay_connection_error',
                $exception
            );
        }

        return $this->failure(
            match ($operation) {
                'create' => 'No fue posible crear la transaccion Webpay.',
                'status' => 'No fue posible consultar la transaccion Webpay.',
                default => 'No fue posible confirmar la transaccion Webpay.',
            },
            'webpay_' . $operation . '_error',
            $exception
        );
    }
}
