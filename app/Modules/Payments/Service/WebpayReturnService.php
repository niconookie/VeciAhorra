<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Service;

use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayException;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\WebpayCommitResult;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnContext;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnContextRepositoryInterface;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnGatewayResolverInterface;
use VeciAhorra\Modules\Payments\Gateway\WebpayTransactionReference;
use VeciAhorra\Modules\Payments\Models\WebpayReturnResult;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;
use VeciAhorra\Modules\Payments\Repository\WebpayReturnRepository;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Service\WebpayReconciliationMaterializer;
use VeciAhorra\Modules\Payments\Requests\WebpayReturnRequest;
use VeciAhorra\Modules\Payments\Support\WebpayTokenReference;
use VeciAhorra\Modules\Payments\Reconciliation\Support\WordPressSiteScope;

final class WebpayReturnService
{
    public function __construct(
        private WebpayReturnGatewayInterface $gateway,
        private PaymentSessionRepository $sessions,
        private WebpayReturnRepository $returns,
        private ?WebpayReturnContextRepositoryInterface $contexts = null,
        private ?WebpayReturnGatewayResolverInterface $gatewayResolver = null,
        private ?PaymentOriginContextRepository $durableOrigins = null,
        private ?WebpayReconciliationMaterializer $materializer = null
    ) {
    }

    public function process(WebpayReturnRequest $request): WebpayReturnResult
    {
        $tokenHash = WebpayTokenReference::hash($request->token);
        $session = $this->sessions->findByProviderSessionId($request->token);
        $externalContext = $session === null
            ? $this->contexts?->find($tokenHash)
            : null;
        $durableOrigin = ($this->durableOrigins
            ?? new PaymentOriginContextRepository())->findByTokenHash($tokenHash);
        $this->assertDurablePublicAttempt($durableOrigin, $session, $tokenHash);
        $reference = WebpayTokenReference::masked($request->token);
        $now = current_time('mysql');
        $claim = $this->returns->claim(
            $tokenHash,
            isset($session['id']) ? (int) $session['id'] : null,
            $request->flow,
            $now
        );

        if (($claim['claimed'] ?? false) !== true) {
            $row = is_array($claim['row'] ?? null) ? $claim['row'] : [];

            if (($row['processing_status'] ?? null) === 'retryable') {
                if (! $this->returns->retry($tokenHash, $now)) {
                    return $this->repeated(
                        $row,
                        $reference,
                        $tokenHash,
                        $durableOrigin
                    );
                }
            } else {
                return $this->repeated(
                    $row,
                    $reference,
                    $tokenHash,
                    $durableOrigin
                );
            }
        }

        if ($request->flow === 'abort') {
            return $this->abort(
                $request,
                $session,
                $externalContext,
                $durableOrigin,
                $tokenHash,
                $reference
            );
        }

        if ($session === null && $externalContext === null) {
            return $this->finalize(new WebpayReturnResult(
                'inconsistent',
                null,
                $reference
            ), $tokenHash);
        }

        try {
            $gateway = $this->gatewayResolver?->resolve($externalContext)
                ?? $this->gateway;
            $financial = $gateway->commit($request->token);
        } catch (PaymentGatewayException $exception) {
            if ($exception->errorCode() === 'webpay_incomplete_response') {
                return $this->finalize(new WebpayReturnResult(
                    'inconsistent',
                    isset($session['id']) ? (int) $session['id'] : null,
                    $reference
                ), $tokenHash, $externalContext, $durableOrigin);
            }

            if ($durableOrigin?->origin()
                === DurablePaymentOrigin::ORIGIN_VECIAHORRA) {
                $this->returns->ambiguous($tokenHash, current_time('mysql'));
            } else {
                $this->returns->fail($tokenHash, current_time('mysql'));
            }

            return new WebpayReturnResult(
                'gateway_error',
                isset($session['id']) ? (int) $session['id'] : null,
                $reference,
                null,
                null,
                $durableOrigin?->origin()
                    === DurablePaymentOrigin::ORIGIN_VECIAHORRA
                        ? $durableOrigin->originResourceId()
                        : null
            );
        }

        if ($durableOrigin !== null) {
            $expectedBuyOrder = $durableOrigin->buyOrder();
            $expectedSessionId = $durableOrigin->financialSessionId();
            $expectedAmount = $durableOrigin->amountClp();
        } elseif ($externalContext !== null) {
            $expectedBuyOrder = $externalContext->buyOrder;
            $expectedSessionId = $externalContext->sessionId;
            $expectedAmount = $externalContext->amount;
        } else {
            $expectedBuyOrder = WebpayTransactionReference::buyOrder(
                (string) $session['checkout_public_id'],
                (string) $session['idempotency_key']
            );
            $expectedSessionId = WebpayTransactionReference::sessionId(
                (string) $session['checkout_public_id']
            );
            $expectedAmount = $this->amount((string) $session['amount']);
        }
        $consistent = hash_equals($expectedBuyOrder, $financial->buyOrder)
            && hash_equals($expectedSessionId, $financial->sessionId)
            && $expectedAmount === $financial->amount;
        $result = $consistent
            ? ($financial->isApproved() ? 'approved' : 'rejected')
            : 'inconsistent';

        return $this->finalize(new WebpayReturnResult(
            $result,
            isset($session['id']) ? (int) $session['id'] : null,
            $reference,
            $financial->toArray()
        ), $tokenHash, $externalContext, $durableOrigin, $financial);
    }

    private function assertDurablePublicAttempt(
        ?DurablePaymentOrigin $origin,
        ?array $session,
        string $tokenHash
    ): void {
        if ($origin === null) {
            return;
        }
        if ($origin->tokenHash() === null
            || ! hash_equals($origin->tokenHash(), $tokenHash)) {
            throw new \InvalidArgumentException(
                'El retorno Webpay no corresponde a un intento durable.'
            );
        }
        if ($origin->origin() !== DurablePaymentOrigin::ORIGIN_VECIAHORRA) {
            return;
        }
        if ($session === null
            || $origin->gatewayId() !== 'webpay_plus'
            || $origin->siteScope() !== WordPressSiteScope::current()
            || $origin->currency() !== 'CLP'
            || ! hash_equals(
                $origin->paymentAttemptId(),
                (string) ($session['public_id'] ?? '')
            )
            || ! hash_equals(
                $origin->originResourceId(),
                (string) ($session['checkout_public_id'] ?? '')
            )
            || $origin->amountClp() !== $this->amount(
                (string) ($session['amount'] ?? '')
            )) {
            throw new \InvalidArgumentException(
                'El retorno Webpay no corresponde a un intento durable.'
            );
        }
    }

    private function abort(
        WebpayReturnRequest $request,
        ?array $session,
        ?WebpayReturnContext $externalContext,
        ?DurablePaymentOrigin $durableOrigin,
        string $tokenHash,
        string $reference
    ): WebpayReturnResult {
        $consistent = true;

        if ($durableOrigin !== null) {
            $consistent = ($request->buyOrder === null
                    || hash_equals($durableOrigin->buyOrder(), $request->buyOrder))
                && ($request->sessionId === null
                    || hash_equals(
                        $durableOrigin->financialSessionId(),
                        $request->sessionId
                    ));
        } elseif ($externalContext !== null) {
            $expectedBuyOrder = $externalContext->buyOrder;
            $expectedSessionId = $externalContext->sessionId;
            $consistent = ($request->buyOrder === null
                    || hash_equals($expectedBuyOrder, $request->buyOrder))
                && ($request->sessionId === null
                    || hash_equals($expectedSessionId, $request->sessionId));
        } elseif ($session !== null) {
            $checkoutId = (string) $session['checkout_public_id'];
            $expectedBuyOrder = WebpayTransactionReference::buyOrder(
                $checkoutId,
                (string) $session['idempotency_key']
            );
            $expectedSessionId = WebpayTransactionReference::sessionId(
                $checkoutId
            );
            $consistent = ($request->buyOrder === null
                    || hash_equals($expectedBuyOrder, $request->buyOrder))
                && ($request->sessionId === null
                    || hash_equals($expectedSessionId, $request->sessionId));
        }

        return $this->finalize(new WebpayReturnResult(
            $consistent ? 'aborted' : 'inconsistent',
            isset($session['id']) ? (int) $session['id'] : null,
            $reference
        ), $tokenHash, $externalContext, $durableOrigin);
    }

    private function repeated(
        array $row,
        string $reference,
        string $tokenHash,
        ?DurablePaymentOrigin $durableOrigin
    ): WebpayReturnResult {
        $stored = isset($row['result_json']) && is_string($row['result_json'])
            ? json_decode($row['result_json'], true)
            : null;
        $storedFinancial = is_array($stored['financial'] ?? null)
            ? $stored['financial']
            : null;

        if ($durableOrigin !== null) {
            $materializer = $this->materializer
                ?? new WebpayReconciliationMaterializer();
            $resumed = $materializer->resume($tokenHash, $durableOrigin);
            $resultStatus = is_string($row['result_status'] ?? null)
                ? $row['result_status']
                : null;

            if (
                $resumed === null
                && $storedFinancial !== null
                && in_array($resultStatus, ['approved', 'rejected'], true)
            ) {
                $materializer->materialize(
                    $tokenHash,
                    $durableOrigin,
                    $this->storedCommit($storedFinancial),
                    $resultStatus
                );
            }
        }

        return new WebpayReturnResult(
            'already_processed',
            isset($row['payment_session_id'])
                && (int) $row['payment_session_id'] > 0
                    ? (int) $row['payment_session_id']
                    : null,
            $reference,
            $storedFinancial,
            is_string($row['result_status'] ?? null)
                ? $row['result_status']
                : (string) ($row['processing_status'] ?? 'processing'),
            $durableOrigin?->origin() === DurablePaymentOrigin::ORIGIN_VECIAHORRA
                ? $durableOrigin->originResourceId()
                : null
        );
    }

    private function finalize(
        WebpayReturnResult $result,
        string $tokenHash,
        ?WebpayReturnContext $externalContext = null,
        ?DurablePaymentOrigin $durableOrigin = null,
        ?\VeciAhorra\Modules\Payments\Gateway\WebpayCommitResult $financial = null
    ): WebpayReturnResult {
        $this->returns->complete(
            $tokenHash,
            $result->result,
            $result->toArray(),
            current_time('mysql')
        );

        if (
            $durableOrigin !== null
            && $financial !== null
            && in_array($result->result, ['approved', 'rejected'], true)
        ) {
            ($this->materializer ?? new WebpayReconciliationMaterializer())
                ->materialize(
                    $tokenHash,
                    $durableOrigin,
                    $financial,
                    $result->result
                );
        }

        if ($externalContext !== null) {
            $this->contexts?->forget($tokenHash);
        }

        if ($durableOrigin?->origin() === DurablePaymentOrigin::ORIGIN_VECIAHORRA) {
            return new WebpayReturnResult(
                $result->result,
                $result->paymentSessionId,
                $result->tokenReference,
                $result->financial,
                $result->previousResult,
                $durableOrigin->originResourceId()
            );
        }

        return $result;
    }

    private function amount(string $amount): int
    {
        if (preg_match('/^(\d+)\.00$/D', $amount, $matches) !== 1) {
            return -1;
        }

        $value = filter_var($matches[1], FILTER_VALIDATE_INT);

        return $value === false || $value <= 0 ? -1 : $value;
    }

    /** @param array<string, mixed> $data */
    private function storedCommit(array $data): WebpayCommitResult
    {
        return new WebpayCommitResult(
            (string) ($data['status'] ?? ''),
            (int) ($data['response_code'] ?? PHP_INT_MIN),
            (int) ($data['amount'] ?? 0),
            (string) ($data['buy_order'] ?? ''),
            (string) ($data['session_id'] ?? ''),
            is_string($data['authorization_code'] ?? null)
                ? $data['authorization_code']
                : null,
            is_string($data['payment_type_code'] ?? null)
                ? $data['payment_type_code']
                : null,
            is_int($data['installments_number'] ?? null)
                ? $data['installments_number']
                : null,
            is_string($data['accounting_date'] ?? null)
                ? $data['accounting_date']
                : null,
            is_string($data['transaction_date'] ?? null)
                ? $data['transaction_date']
                : null,
            is_string($data['card_last_four'] ?? null)
                ? $data['card_last_four']
                : null,
            is_int($data['balance'] ?? null) || is_float($data['balance'] ?? null)
                ? $data['balance']
                : null
        );
    }
}
