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
        $configured = self::configuredValue(
            'VECIAHORRA_PAYMENT_GATEWAY',
            'payment_gateway'
        );
        $gateway = $configured === null
            ? (self::woocommerceWebpayEnabled()
                ? self::GATEWAY_WEBPAY
                : self::GATEWAY_MOCK)
            : strtolower(trim($configured));

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
        $gatewayConfigured = self::configuredValue(
            'VECIAHORRA_PAYMENT_GATEWAY',
            'payment_gateway'
        ) !== null;
        $configured = [
            self::configuredValue('VECIAHORRA_WEBPAY_ENVIRONMENT', 'webpay_environment'),
            self::configuredValue('VECIAHORRA_WEBPAY_COMMERCE_CODE', 'webpay_commerce_code'),
            self::configuredValue('VECIAHORRA_WEBPAY_API_KEY', 'webpay_api_key'),
            self::configuredValue('VECIAHORRA_WEBPAY_RETURN_URL', 'webpay_return_url'),
        ];

        if (! $gatewayConfigured && $configured === [null, null, null, null]) {
            $settings = get_option(
                'woocommerce_veciahorra_webpay_plus_settings',
                []
            );

            if (is_array($settings) && self::woocommerceWebpayEnabled($settings)) {
                return new WebpayGatewayConfiguration(
                    (string) ($settings['mode'] ?? 'integration'),
                    (string) ($settings['commerce_code'] ?? ''),
                    (string) ($settings['api_key'] ?? ''),
                    home_url('/wp-json/veciahorra/v1/payments/webpay/return')
                );
            }
        }

        return new WebpayGatewayConfiguration(
            $configured[0] ?? 'integration',
            $configured[1] ?? '',
            $configured[2] ?? '',
            $configured[3] ?? ''
        );
    }

    private static function woocommerceWebpayEnabled(?array $settings = null): bool
    {
        $settings ??= get_option(
            'woocommerce_veciahorra_webpay_plus_settings',
            []
        );

        return is_array($settings)
            && ($settings['enabled'] ?? 'no') === 'yes';
    }

    private static function configuredValue(
        string $constant,
        string $environment
    ): ?string {
        if (defined($constant)) {
            $value = constant($constant);

            return is_string($value) ? $value : '';
        }

        $value = getenv($environment);

        return is_string($value) && $value !== '' ? $value : null;
    }

}
