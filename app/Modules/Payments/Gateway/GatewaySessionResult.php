<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Gateway;

use InvalidArgumentException;

final class GatewaySessionResult
{
    public const STATUS_READY = 'ready';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    public function __construct(
        public readonly string $provider,
        public readonly string $providerSessionId,
        public readonly string $status,
        public readonly ?string $redirectUrl,
        public readonly string $expiresAt,
        public readonly ?string $errorCode = null
    ) {
        if (
            $provider === ''
            || $providerSessionId === ''
            || ! in_array($status, [
                self::STATUS_READY,
                self::STATUS_REJECTED,
                self::STATUS_EXPIRED,
            ], true)
            || $expiresAt === ''
        ) {
            throw new InvalidArgumentException(
                'El resultado de la sesion del gateway no es valido.'
            );
        }
    }
}
