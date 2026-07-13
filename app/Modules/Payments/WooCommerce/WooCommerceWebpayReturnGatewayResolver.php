<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\WooCommerce;

use Throwable;
use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayException;
use VeciAhorra\Modules\Payments\Gateway\WebpayPaymentGateway;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnContext;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnGatewayInterface;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnGatewayResolverInterface;

final class WooCommerceWebpayReturnGatewayResolver implements
    WebpayReturnGatewayResolverInterface
{
    public function __construct(
        private WebpayReturnGatewayInterface $fallback
    ) {
    }

    public function resolve(
        ?WebpayReturnContext $context
    ): WebpayReturnGatewayInterface {
        if ($context === null) {
            return $this->fallback;
        }

        try {
            $settings = get_option(
                'woocommerce_' . WebpayPlusGateway::GATEWAY_ID . '_settings',
                []
            );
            $configuration = WebpayGatewaySettings::configuration(
                is_array($settings) ? $settings : []
            );
        } catch (Throwable $exception) {
            throw new PaymentGatewayException(
                'La configuracion Webpay del retorno no es valida.',
                'webpay_return_configuration_error',
                $exception
            );
        }

        if (
            ! hash_equals($context->environment, $configuration->environment)
            || ! hash_equals($context->commerceCode, $configuration->commerceCode)
        ) {
            throw new PaymentGatewayException(
                'La configuracion Webpay cambio durante la transaccion.',
                'webpay_context_configuration_mismatch'
            );
        }

        try {
            return new WebpayPaymentGateway($configuration);
        } catch (Throwable $exception) {
            throw new PaymentGatewayException(
                'No fue posible preparar Webpay para el retorno.',
                'webpay_return_configuration_error',
                $exception
            );
        }
    }
}
