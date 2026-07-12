<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

use InvalidArgumentException;

final class WebpayGatewayConfiguration
{
    public function __construct(
        public readonly string $environment,
        public readonly string $commerceCode,
        private readonly string $apiKey,
        public readonly string $returnUrl
    ) {
        if ($environment !== 'integration') {
            throw new InvalidArgumentException(
                'Webpay solo admite el ambiente integration en este hito.'
            );
        }

        if (
            preg_match('/^\d{6,32}$/D', $commerceCode) !== 1
            || preg_match('/^[A-Za-z0-9]{32,256}$/D', $apiKey) !== 1
        ) {
            throw new InvalidArgumentException(
                'Las credenciales Webpay no son validas.'
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
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }
}
