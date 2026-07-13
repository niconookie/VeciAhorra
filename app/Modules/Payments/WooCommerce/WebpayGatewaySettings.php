<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\WooCommerce;

use VeciAhorra\Modules\Payments\Gateway\WebpayGatewayConfiguration;

final class WebpayGatewaySettings
{
    public static function configuration(array $settings): WebpayGatewayConfiguration
    {
        return new WebpayGatewayConfiguration(
            (string) ($settings['mode'] ?? 'integration'),
            (string) ($settings['commerce_code'] ?? ''),
            (string) ($settings['api_key'] ?? ''),
            (string) rest_url('veciahorra/v1/payments/webpay/return')
        );
    }
}
