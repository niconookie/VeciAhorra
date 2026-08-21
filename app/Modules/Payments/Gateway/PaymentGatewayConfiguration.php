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
        $deploymentEnvironment = self::productionEnvironmentValue(
            'VECIAHORRA_WEBPAY_ENVIRONMENT'
        );
        if (is_string($deploymentEnvironment)
            && strtolower(trim($deploymentEnvironment)) === 'production') {
            $productionGateway = self::productionEnvironmentValue(
                'VECIAHORRA_PAYMENT_GATEWAY'
            );
            if (! is_string($productionGateway)
                || strtolower(trim($productionGateway)) !== self::GATEWAY_WEBPAY) {
                throw new InvalidArgumentException(
                    'La configuracion Webpay productiva esta incompleta.'
                );
            }

            return self::GATEWAY_WEBPAY;
        }

        $constantEnvironment = defined('VECIAHORRA_WEBPAY_ENVIRONMENT')
            ? constant('VECIAHORRA_WEBPAY_ENVIRONMENT')
            : null;
        $legacyEnvironment = getenv('webpay_environment');
        if ((is_string($constantEnvironment)
                && strtolower(trim($constantEnvironment)) === 'production')
            || (is_string($legacyEnvironment)
                && strtolower(trim($legacyEnvironment)) === 'production')) {
            throw self::productionAuthorityException();
        }

        $settings = get_option(
            'woocommerce_veciahorra_webpay_plus_settings',
            []
        );
        if (is_array($settings)
            && strtolower(trim((string) ($settings['mode'] ?? 'integration')))
                === 'production') {
            throw self::productionAuthorityException();
        }

        $configured = self::deploymentValue('VECIAHORRA_PAYMENT_GATEWAY')
            ?? self::configuredValue(
                'VECIAHORRA_PAYMENT_GATEWAY',
                'payment_gateway'
            );
        $gateway = $configured === null
            ? (self::woocommerceWebpayEnabled(is_array($settings) ? $settings : [])
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
        $deploymentEnvironment = self::productionEnvironmentValue(
            'VECIAHORRA_WEBPAY_ENVIRONMENT'
        );

        if ($deploymentEnvironment !== null) {
            $normalizedEnvironment = strtolower(trim($deploymentEnvironment));
            if ($normalizedEnvironment === 'production') {
                return self::productionWebpay();
            }
            if ($normalizedEnvironment !== 'integration') {
                throw new InvalidArgumentException(
                    'El ambiente Webpay configurado no es valido.'
                );
            }
        }

        $constantEnvironment = defined('VECIAHORRA_WEBPAY_ENVIRONMENT')
            ? constant('VECIAHORRA_WEBPAY_ENVIRONMENT')
            : null;
        $legacyEnvironment = getenv('webpay_environment');
        if ((is_string($constantEnvironment)
                && strtolower(trim($constantEnvironment)) === 'production')
            || (is_string($legacyEnvironment)
                && strtolower(trim($legacyEnvironment)) === 'production')) {
            throw self::productionAuthorityException();
        }

        $settings = get_option(
            'woocommerce_veciahorra_webpay_plus_settings',
            []
        );
        if (is_array($settings)
            && strtolower(trim((string) ($settings['mode'] ?? 'integration')))
                === 'production') {
            throw self::productionAuthorityException();
        }

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

    private static function productionWebpay(): WebpayGatewayConfiguration
    {
        $gateway = self::productionEnvironmentValue('VECIAHORRA_PAYMENT_GATEWAY');
        $environment = self::productionEnvironmentValue('VECIAHORRA_WEBPAY_ENVIRONMENT');
        $commerceCode = self::productionEnvironmentValue(
            'VECIAHORRA_WEBPAY_PRODUCTION_COMMERCE_CODE'
        );
        $apiKey = self::productionEnvironmentValue('VECIAHORRA_WEBPAY_PRODUCTION_API_KEY');
        $origin = self::productionEnvironmentValue('VECIAHORRA_PUBLIC_ORIGIN');
        $gate = self::productionEnvironmentValue('VECIAHORRA_WEBPAY_PRODUCTION_ENABLED');

        if (strtolower(trim((string) $gateway)) !== self::GATEWAY_WEBPAY
            || strtolower(trim((string) $environment)) !== 'production'
            || $commerceCode === null
            || $apiKey === null
            || $origin === null) {
            throw new InvalidArgumentException(
                'La configuracion Webpay productiva esta incompleta.'
            );
        }

        if (str_ends_with($origin, '/')) {
            $origin = substr($origin, 0, -1);
        }

        return new WebpayGatewayConfiguration(
            'production',
            $commerceCode,
            $apiKey,
            $origin . '/wp-json/veciahorra/v1/payments/webpay/return',
            $gate === '1'
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

    private static function deploymentValue(string $name): ?string
    {
        if (defined($name)) {
            $value = constant($name);

            return is_string($value) && $value !== '' ? $value : null;
        }

        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function productionEnvironmentValue(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function productionAuthorityException(): InvalidArgumentException
    {
        return new InvalidArgumentException(
            'Webpay productivo solo puede configurarse mediante el entorno de despliegue.'
        );
    }

}
