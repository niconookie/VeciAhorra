<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\WooCommerce\Contracts;

use VeciAhorra\Modules\Payments\Gateway\PaymentSessionContext;
use VeciAhorra\Modules\Payments\Gateway\WebpayGatewayConfiguration;
use VeciAhorra\Modules\Payments\WooCommerce\DTO\WooCommercePaymentAttempt;

interface WooCommercePaymentAttemptServiceInterface
{
    public function newAttemptId(): string;

    public function create(
        \WC_Order $order,
        WebpayGatewayConfiguration $configuration,
        PaymentSessionContext $paymentContext,
        string $paymentAttemptId
    ): WooCommercePaymentAttempt;

    public function bindToken(
        WooCommercePaymentAttempt $attempt,
        string $providerSessionId
    ): void;
}
