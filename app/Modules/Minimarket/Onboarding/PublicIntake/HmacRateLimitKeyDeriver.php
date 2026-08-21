<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

final class HmacRateLimitKeyDeriver implements RateLimitKeyDeriver
{
    private string $key;

    public function __construct(?string $salt = null)
    {
        $salt ??= wp_salt('auth');
        if (strlen($salt) < 32) throw new PublicIntakeException('rate_limit_unavailable');
        $key = hash_hkdf('sha256', $salt, 32, 'veciahorra|minimarket-onboarding-r1c|rate-limit|v1', '');
        if (! is_string($key) || strlen($key) !== 32) throw new PublicIntakeException('rate_limit_unavailable');
        $this->key = $key;
    }

    public function derive(string $domain, string $value): string
    {
        if ($domain === '' || $value === '') throw new PublicIntakeException('rate_limit_unavailable');
        return hash_hmac('sha256', $domain . "\0" . $value, $this->key);
    }
}
