<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

use InvalidArgumentException;

final class PaymentGatewayConfiguration
{
    public const GATEWAY_MOCK = 'mock';
    public const GATEWAY_WEBPAY = 'webpay';

    public static function gateway(): string
    {
        $gateway = strtolower(trim(self::value(
            'VECIAHORRA_PAYMENT_GATEWAY',
            'payment_gateway',
            self::GATEWAY_MOCK
        )));

        if (! in_array($gateway, [
            self::GATEWAY_MOCK,
            self::GATEWAY_WEBPAY,
        ], true)) {
            throw new InvalidArgumentException(
                'El payment_gateway configurado no es valido.'
            );
        }

        return $gateway;
    }

    public static function webpay(): WebpayGatewayConfiguration
    {
        return new WebpayGatewayConfiguration(
            self::value(
                'VECIAHORRA_WEBPAY_ENVIRONMENT',
                'webpay_environment',
                'integration'
            ),
            self::value(
                'VECIAHORRA_WEBPAY_COMMERCE_CODE',
                'webpay_commerce_code'
            ),
            self::value(
                'VECIAHORRA_WEBPAY_API_KEY',
                'webpay_api_key'
            ),
            self::value(
                'VECIAHORRA_WEBPAY_RETURN_URL',
                'webpay_return_url'
            )
        );
    }

    private static function value(
        string $constant,
        string $environment,
        string $default = ''
    ): string {
        if (defined($constant)) {
            $value = constant($constant);

            return is_string($value) ? $value : '';
        }

        $value = getenv($environment);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $default;
    }
}
