<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

use InvalidArgumentException;

final class WebpayGatewayConfiguration
{
    public readonly string $environment;
    public readonly string $commerceCode;
    private readonly string $apiKey;
    public readonly string $returnUrl;
    public readonly bool $productionCreationEnabled;

    public function __construct(
        string $environment,
        string $commerceCode,
        string $apiKey,
        string $returnUrl,
        bool $productionCreationEnabled = false
    ) {
        $rawReturnUrl = $returnUrl;
        $environment = strtolower(trim($environment));
        $commerceCode = trim($commerceCode);
        $apiKey = trim($apiKey);
        $returnUrl = trim($returnUrl);

        if (! in_array($environment, ['integration', 'production'], true)) {
            throw new InvalidArgumentException(
                'El ambiente Webpay configurado no es valido.'
            );
        }

        if (preg_match('/^\d{6,32}$/D', $commerceCode) !== 1) {
            throw new InvalidArgumentException(
                'El codigo de comercio Webpay no es valido.'
            );
        }

        if (preg_match('/^[A-Za-z0-9]{32,256}$/D', $apiKey) !== 1) {
            throw new InvalidArgumentException(
                'La API Key Webpay no es valida.'
            );
        }

        $url = filter_var($returnUrl, FILTER_VALIDATE_URL);

        if (
            $url === false
            || strtolower((string) parse_url($returnUrl, PHP_URL_SCHEME))
                !== 'https'
        ) {
            throw new InvalidArgumentException(
                'La webpay_return_url debe ser una URL HTTPS valida.'
            );
        }

        if ($environment === 'production') {
            $parts = parse_url($returnUrl);
            $originalHost = is_array($parts)
                ? (string) ($parts['host'] ?? '')
                : '';
            $host = strtolower($originalHost);

            if (
                ! is_array($parts)
                || $rawReturnUrl !== trim($rawReturnUrl)
                || preg_match('/[\x00-\x1F\x7F]/', $rawReturnUrl) === 1
                || ! self::isCanonicalDnsHost($originalHost)
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['port'])
                || isset($parts['query'])
                || isset($parts['fragment'])
                || ($parts['path'] ?? '')
                    !== '/wp-json/veciahorra/v1/payments/webpay/return'
            ) {
                throw new InvalidArgumentException(
                    'La URL de retorno Webpay productiva no es valida.'
                );
            }

            $returnUrl = 'https://' . $host
                . '/wp-json/veciahorra/v1/payments/webpay/return';
        }

        $this->environment = $environment;
        $this->commerceCode = $commerceCode;
        $this->apiKey = $apiKey;
        $this->returnUrl = $returnUrl;
        $this->productionCreationEnabled = $productionCreationEnabled;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    private static function isCanonicalDnsHost(string $host): bool
    {
        if ($host === ''
            || strlen($host) > 253
            || preg_match('/[^A-Za-z0-9.-]/D', $host) === 1
            || str_ends_with($host, '.')
            || preg_match('/^[0-9.]+$/D', $host) === 1) {
            return false;
        }

        $labels = explode('.', strtolower($host));
        if (count($labels) < 2
            || end($labels) === 'localhost'
            || end($labels) === 'local'
            || preg_match('/^\d+$/D', (string) end($labels)) === 1) {
            return false;
        }

        foreach ($labels as $label) {
            if ($label === ''
                || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label) !== 1) {
                return false;
            }
        }

        return true;
    }
}
