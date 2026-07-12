<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

use Throwable;
use GuzzleHttp\Exception\ConnectException;
use Transbank\Webpay\Options;
use Transbank\Webpay\WebpayPlus\Transaction;
use VeciAhorra\Modules\Payments\Models\PaymentConfirmationResult;

final class WebpayPaymentGateway implements
    PaymentGatewayInterface,
    PaymentConfirmationGatewayInterface
{
    private const PROVIDER = 'webpay_plus';
    private const INTEGRATION_HOST = 'webpay3gint.transbank.cl';

    private object $transaction;

    public function __construct(
        private WebpayGatewayConfiguration $configuration,
        ?object $transaction = null
    ) {
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
        $buyOrder = $this->buyOrder(
            $context->checkoutId,
            $context->idempotencyKey
        );
        $sessionId = $this->sessionId($context->checkoutId);

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
        $this->assertToken($providerReference);

        try {
            $response = $this->transaction->commit($providerReference);

            return $this->confirmationResult($response);
        } catch (PaymentGatewayException $exception) {
            throw $exception;
        } catch (Throwable) {
            try {
                $status = $this->transaction->status($providerReference);

                return $this->confirmationResult($status);
            } catch (Throwable $statusException) {
                throw $this->sdkFailure('commit', $statusException);
            }
        }
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

    private function buyOrder(string $checkoutId, string $idempotencyKey): string
    {
        return 'VA' . strtoupper(substr(hash(
            'sha256',
            $checkoutId . '|' . $idempotencyKey
        ), 0, 24));
    }

    private function sessionId(string $checkoutId): string
    {
        return 'VA-' . strtoupper(substr(hash('sha256', $checkoutId), 0, 58));
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
        if (
            ! is_string($url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || strtolower((string) parse_url($url, PHP_URL_HOST))
                !== self::INTEGRATION_HOST
        ) {
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
