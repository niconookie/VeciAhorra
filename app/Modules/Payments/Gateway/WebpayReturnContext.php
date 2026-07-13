<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

use InvalidArgumentException;

final class WebpayReturnContext
{
    public const SOURCE_WOOCOMMERCE = 'woocommerce';

    public function __construct(
        public readonly string $source,
        public readonly string $environment,
        public readonly string $commerceCode,
        public readonly string $buyOrder,
        public readonly string $sessionId,
        public readonly int $amount,
        public readonly int $expiresAt
    ) {
        if (
            $source !== self::SOURCE_WOOCOMMERCE
            || ! in_array($environment, ['integration', 'production'], true)
            || preg_match('/^\d{6,32}$/D', $commerceCode) !== 1
            || preg_match('/^VA[A-F0-9]{24}$/D', $buyOrder) !== 1
            || preg_match('/^VA-[A-F0-9]{58}$/D', $sessionId) !== 1
            || $amount <= 0
            || $expiresAt <= 0
        ) {
            throw new InvalidArgumentException(
                'El contexto temporal Webpay no es valido.'
            );
        }
    }

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'environment' => $this->environment,
            'commerce_code' => $this->commerceCode,
            'buy_order' => $this->buyOrder,
            'session_id' => $this->sessionId,
            'amount' => $this->amount,
            'expires_at' => $this->expiresAt,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['source'] ?? ''),
            (string) ($data['environment'] ?? ''),
            (string) ($data['commerce_code'] ?? ''),
            (string) ($data['buy_order'] ?? ''),
            (string) ($data['session_id'] ?? ''),
            is_int($data['amount'] ?? null) ? $data['amount'] : 0,
            is_int($data['expires_at'] ?? null) ? $data['expires_at'] : 0
        );
    }
}
