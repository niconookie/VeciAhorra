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

    public function __construct(
        string $environment,
        string $commerceCode,
        string $apiKey,
        string $returnUrl
    ) {
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

        $this->environment = $environment;
        $this->commerceCode = $commerceCode;
        $this->apiKey = $apiKey;
        $this->returnUrl = $returnUrl;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }
}
