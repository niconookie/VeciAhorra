<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final class RemoteAddressResolver
{
    /** @param array<string, mixed> $server */
    public function resolve(array $server): PublicClientAddress
    {
        $raw = $server['REMOTE_ADDR'] ?? null;
        if (! is_string($raw) || $raw === '') throw new PublicIntakeException('invalid_remote_address');
        $packed = @inet_pton($raw);
        if ($packed === false) throw new PublicIntakeException('invalid_remote_address');
        if (strlen($packed) === 4) return new PublicClientAddress($packed, 32);
        if (strlen($packed) === 16) return new PublicClientAddress(substr($packed, 0, 8), 64);
        throw new PublicIntakeException('invalid_remote_address');
    }
}
