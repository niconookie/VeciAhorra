<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Repository;

use RuntimeException;
use Throwable;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnContext;
use VeciAhorra\Modules\Payments\Gateway\WebpayReturnContextRepositoryInterface;

final class TransientWebpayReturnContextRepository implements
    WebpayReturnContextRepositoryInterface
{
    private const PREFIX = 'va_webpay_return_';

    public function store(
        string $tokenHash,
        WebpayReturnContext $context,
        int $ttl
    ): void {
        $this->assertHash($tokenHash);

        if ($ttl <= 0 || ! set_transient(
            self::PREFIX . $tokenHash,
            $context->toArray(),
            $ttl
        )) {
            throw new RuntimeException(
                'No fue posible guardar el contexto temporal Webpay.'
            );
        }
    }

    public function find(string $tokenHash): ?WebpayReturnContext
    {
        $this->assertHash($tokenHash);
        $stored = get_transient(self::PREFIX . $tokenHash);

        if (! is_array($stored)) {
            return null;
        }

        try {
            $context = WebpayReturnContext::fromArray($stored);
        } catch (Throwable) {
            $this->forget($tokenHash);

            return null;
        }

        if ($context->expiresAt < time()) {
            $this->forget($tokenHash);

            return null;
        }

        return $context;
    }

    public function forget(string $tokenHash): void
    {
        $this->assertHash($tokenHash);
        delete_transient(self::PREFIX . $tokenHash);
    }

    private function assertHash(string $tokenHash): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $tokenHash) !== 1) {
            throw new RuntimeException(
                'La referencia del contexto Webpay no es valida.'
            );
        }
    }
}
