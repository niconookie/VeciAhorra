<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Service;

use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayException;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnContext;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnContextRepositoryInterface;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnGatewayResolverInterface;
use VeciAhorra\Modules\Payments\Gateway\WebpayTransactionReference;
use VeciAhorra\Modules\Payments\Models\WebpayReturnResult;
use VeciAhorra\Modules\Payments\Repository\PaymentSessionRepository;
use VeciAhorra\Modules\Payments\Repository\WebpayReturnRepository;
use VeciAhorra\Modules\Payments\Requests\WebpayReturnRequest;
use VeciAhorra\Modules\Payments\Support\WebpayTokenReference;

final class WebpayReturnService
{
    public function __construct(
        private WebpayReturnGatewayInterface $gateway,
        private PaymentSessionRepository $sessions,
        private WebpayReturnRepository $returns,
        private ?WebpayReturnContextRepositoryInterface $contexts = null,
        private ?WebpayReturnGatewayResolverInterface $gatewayResolver = null
    ) {
    }

    public function process(WebpayReturnRequest $request): WebpayReturnResult
    {
        $tokenHash = WebpayTokenReference::hash($request->token);
        $session = $this->sessions->findByProviderSessionId($request->token);
        $externalContext = $session === null
            ? $this->contexts?->find($tokenHash)
            : null;
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
                    return $this->repeated($row, $reference);
                }
            } else {
                return $this->repeated($row, $reference);
            }
        }

        if ($request->flow === 'abort') {
            return $this->abort(
                $request,
                $session,
                $externalContext,
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
                ), $tokenHash, $externalContext);
            }

            $this->returns->fail($tokenHash, current_time('mysql'));

            return new WebpayReturnResult(
                'gateway_error',
                isset($session['id']) ? (int) $session['id'] : null,
                $reference
            );
        }

        if ($externalContext !== null) {
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
        ), $tokenHash, $externalContext);
    }

    private function abort(
        WebpayReturnRequest $request,
        ?array $session,
        ?WebpayReturnContext $externalContext,
        string $tokenHash,
        string $reference
    ): WebpayReturnResult {
        $consistent = true;

        if ($externalContext !== null) {
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
        ), $tokenHash, $externalContext);
    }

    private function repeated(array $row, string $reference): WebpayReturnResult
    {
        $stored = isset($row['result_json']) && is_string($row['result_json'])
            ? json_decode($row['result_json'], true)
            : null;

        return new WebpayReturnResult(
            'already_processed',
            isset($row['payment_session_id'])
                && (int) $row['payment_session_id'] > 0
                    ? (int) $row['payment_session_id']
                    : null,
            $reference,
            is_array($stored['financial'] ?? null)
                ? $stored['financial']
                : null,
            is_string($row['result_status'] ?? null)
                ? $row['result_status']
                : (string) ($row['processing_status'] ?? 'processing')
        );
    }

    private function finalize(
        WebpayReturnResult $result,
        string $tokenHash,
        ?WebpayReturnContext $externalContext = null
    ): WebpayReturnResult {
        $this->returns->complete(
            $tokenHash,
            $result->result,
            $result->toArray(),
            current_time('mysql')
        );

        if ($externalContext !== null) {
            $this->contexts?->forget($tokenHash);
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
}
