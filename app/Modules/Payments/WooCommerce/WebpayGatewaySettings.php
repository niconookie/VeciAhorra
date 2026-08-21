<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\WooCommerce;

use VeciAhorra\Modules\Payments\Gateway\PaymentGatewayConfiguration;
use VeciAhorra\Modules\Payments\Gateway\WebpayGatewayConfiguration;
use InvalidArgumentException;

final class WebpayGatewaySettings
{
    public static function configuration(array $settings): WebpayGatewayConfiguration
    {
        $deploymentEnvironment = getenv('VECIAHORRA_WEBPAY_ENVIRONMENT');
        if (is_string($deploymentEnvironment)
            && strtolower(trim($deploymentEnvironment)) === 'production') {
            return PaymentGatewayConfiguration::webpay();
        }

        if (strtolower(trim((string) ($settings['mode'] ?? 'integration')))
            === 'production') {
            throw new InvalidArgumentException(
                'Webpay productivo solo puede configurarse mediante el entorno de despliegue.'
            );
        }

        return new WebpayGatewayConfiguration(
            (string) ($settings['mode'] ?? 'integration'),
            (string) ($settings['commerce_code'] ?? ''),
            (string) ($settings['api_key'] ?? ''),
            (string) rest_url('veciahorra/v1/payments/webpay/return')
        );
    }
}
