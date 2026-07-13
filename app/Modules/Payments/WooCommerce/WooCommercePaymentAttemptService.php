<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\WooCommerce;

use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Modules\Payments\Gateway\PaymentSessionContext;
use VeciAhorra\Modules\Payments\Gateway\WebpayGatewayConfiguration;
use VeciAhorra\Modules\Payments\Gateway\WebpayTransactionReference;
use VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin;
use VeciAhorra\Modules\Payments\Reconciliation\Exception\DuplicatePaymentOriginContext;
use VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository;
use VeciAhorra\Modules\Payments\Reconciliation\Support\WordPressSiteScope;
use VeciAhorra\Modules\Payments\Support\WebpayTokenReference;
use VeciAhorra\Modules\Payments\WooCommerce\Contracts\WooCommercePaymentAttemptServiceInterface;
use VeciAhorra\Modules\Payments\WooCommerce\DTO\WooCommercePaymentAttempt;

final class WooCommercePaymentAttemptService implements
    WooCommercePaymentAttemptServiceInterface
{
    public const ATTEMPT_META = '_veciahorra_webpay_attempt_v1';
    public const ORIGIN_META = '_veciahorra_webpay_origin_context_v1';
    public const GATEWAY_META = '_veciahorra_webpay_gateway_v1';

    public function __construct(
        private readonly PaymentOriginContextRepository $origins = new PaymentOriginContextRepository()
    ) {
    }

    public function newAttemptId(): string
    {
        return 'attempt_' . bin2hex(random_bytes(16));
    }

    public function create(
        \WC_Order $order,
        WebpayGatewayConfiguration $configuration,
        PaymentSessionContext $paymentContext,
        string $paymentAttemptId
    ): WooCommercePaymentAttempt {
        $now = current_time('mysql', true);
        $origin = new DurablePaymentOrigin(
            'poc_' . bin2hex(random_bytes(20)),
            WordPressSiteScope::current(),
            DurablePaymentOrigin::ORIGIN_WOOCOMMERCE,
            (string) $order->get_id(),
            WebpayPlusGateway::GATEWAY_ID,
            $paymentAttemptId,
            $this->integerAmount($paymentContext->amount),
            $configuration->environment,
            hash('sha256', $configuration->commerceCode),
            WebpayTransactionReference::buyOrder(
                $paymentContext->checkoutId,
                $paymentContext->idempotencyKey
            ),
            WebpayTransactionReference::sessionId($paymentContext->checkoutId),
            null,
            1,
            $now,
            $now,
            $paymentContext->expiresAt
        );

        $stored = $this->origins->findByPaymentAttemptId($paymentAttemptId);

        if ($stored !== null) {
            if (! hash_equals($stored->originKey(), $origin->originKey())) {
                throw new PersistenceException('El intento durable es incompatible.');
            }

            $originId = $this->originId($paymentAttemptId);
        } else {
            try {
                $originId = $this->origins->create($origin);
            } catch (DuplicatePaymentOriginContext) {
                $stored = $this->origins->findByPaymentAttemptId($paymentAttemptId);

                if ($stored === null || ! hash_equals($stored->originKey(), $origin->originKey())) {
                    throw new PersistenceException('El intento durable es incompatible.');
                }

                $originId = $this->originId($paymentAttemptId);
            }
        }

        $order->update_meta_data(self::ATTEMPT_META, $paymentAttemptId);
        $order->update_meta_data(self::ORIGIN_META, (string) $originId);
        $order->update_meta_data(self::GATEWAY_META, WebpayPlusGateway::GATEWAY_ID);
        $order->save();

        return new WooCommercePaymentAttempt($originId, $paymentAttemptId);
    }

    public function bindToken(
        WooCommercePaymentAttempt $attempt,
        string $providerSessionId
    ): void {
        $result = $this->origins->bindTokenHash(
            $attempt->originContextId(),
            $attempt->paymentAttemptId(),
            WebpayTokenReference::hash($providerSessionId),
            current_time('mysql', true)
        );

        if (! $result->bound()) {
            throw new PersistenceException(
                'No fue posible vincular la referencia segura del intento.'
            );
        }
    }

    private function integerAmount(string $amount): int
    {
        if (preg_match('/^([1-9]\d*)\.00$/D', $amount, $matches) !== 1) {
            throw new \InvalidArgumentException('Monto WooCommerce no valido.');
        }

        $value = filter_var($matches[1], FILTER_VALIDATE_INT);

        if ($value === false || $value <= 0) {
            throw new \InvalidArgumentException('Monto WooCommerce no valido.');
        }

        return $value;
    }

    private function originId(string $paymentAttemptId): int
    {
        global $wpdb;

        $id = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . $wpdb->prefix . \VeciAhorra\Core\Config::TABLE_PREFIX
            . 'payment_origin_contexts WHERE payment_attempt_id = %s LIMIT 1',
            $paymentAttemptId
        ));

        if (! is_numeric($id) || (int) $id <= 0) {
            throw new PersistenceException('No fue posible resolver el intento durable.');
        }

        return (int) $id;
    }
}
